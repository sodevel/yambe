<?php

namespace MediaWiki\Extension\Yambe;

use Config;
use Html;
use Parser;
use PPFrame;
use TextContent;
use TitleValue;
use SimpleXMLElement;
use MediaWiki\Hook\EditFormPreloadTextHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;

class Yambe implements ParserFirstCallInitHook, EditFormPreloadTextHook
{
	private $config;
	private $pageStore;
	private $revisionLookup;


	public function __construct(Config $config, PageStore $pageStore, RevisionLookup $revisionLookup)
	{
		$this->config = $config;
		$this->pageStore = $pageStore;
		$this->revisionLookup = $revisionLookup;
	}


	public function onParserFirstCallInit($parser)
	{
		$parser->setHook('yambe:breadcrumb', [$this, 'renderBreadcrumb']);
	}

	public function renderBreadcrumb($input, array $args, Parser $parser, PPFrame $frame)
	{
		$delimiter = $this->config->get('YambeBCdelimiter');
		$maxCount = $this->config->get('YambeBCmaxCount');
		$overflowPrefix = $this->config->get('YambeBCoverflowPrefix');
		$selfLink = $this->config->get('YambeBCselfLink');
		$printable = $this->config->get('YambeBCprintable');

		// Output nothing if maximum count is zero, this effectively disables the extension
		if ($maxCount <= 0) {
			return '';
		}

		$page = $parser->getPage();
		if (is_null($page)) {
			return '';
		}
		$linkRenderer = $parser->getLinkRenderer();

		$selfTitle = TitleValue::newFromPage($page);
		if ($args['self'] != '') {
			$selfText = $args['self'];
		} else {
			$selfText = $selfTitle->getText();
		}

		// The breadcrumbs are build in reverse order and start with the current page
		if ($selfLink) {
			$breadcrumb = $linkRenderer->makeKnownLink($selfTitle, $selfText);
		} else {
			$breadcrumb = $selfText;
		}

		// Used to store all visited pages to prevent circular references, this includes the current page
		$bcList = array();
		array_push($bcList, $selfTitle);

		// Build the breadcrumb chain by following the parent pages
		for ($count = 1; $count < $maxCount; ) {
			// Extract parent page information from the breadcrumb tag content
			$parent = explode('|', $input);
			foreach ($parent as &$element) {
				$element = trim($element);
			}
			unset($element);
			$parentPath = array_shift($parent);
			if ($parentPath == '') {
				break;
			}
			$parentText = array_shift($parent);

			// Check if the parent page exists and test for a circular reference.
			// The breadcrumb chain can't be continued if the parent page does not exist.
			$parentPage = $this->pageStore->getPageByText($parentPath);
			if (is_null($parentPage)) {
				break;
			}
			$parentTitle = TitleValue::newFromPage($parentPage);
			foreach ($bcList as $element) {
				if ($parentTitle->isSameLinkAs($element)) {
					break 2;
				}
			}
			array_push($bcList, $parentTitle);

			if (++$count < $maxCount) {
				// Extend the breadcrumb chain with the parent page
				$parentLink = $linkRenderer->makeKnownLink($parentTitle, $parentText);
				$breadcrumb = $parentLink . $delimiter . $breadcrumb;

				// Retrieve the contents of the parent page to extract a possible present breadcrumb tag
				$parentRevision = $this->revisionLookup->getRevisionByTitle($parentPage);
				if (is_null($parentRevision)) {
					break;
				}
				$parentContent = $parentRevision->getContent("main", RevisionRecord::RAW);
				if (!($parentContent instanceof TextContent)) {
					break;
				}
				$tag = $this->extractTag($parentContent->getText());
				if (is_null($tag)) {
					break;
				}

				// TODO: Adding a template dependency to the parent page will invalidate the parser cache of this page every time
				//       the parent page gets changed. While this is better than disabling the parser cache completely, this
				//       will invalidate the cache too often, it must only be invalidated when the tag on the parent page is changed.
				//       Unfortunately, there seems to be no way to trigger only on that change.
				$parser->getOutput()->addTemplate($parentTitle, $parentRevision->getPageId(), $parentRevision->getId());
				$input = $tag->breadcrumb;
			} else {
				// Maximum breadcrumb element count reached, finish the breadcrumb chain with the overflow prefix
				$breadcrumb = $overflowPrefix . $delimiter . $breadcrumb;
			}
		}

		// Encapsulate the final breadcrumb in its div.
		// The $breadcrumb variable does contain HTML, do not escape special characters here or its content gets broken.
		return Html::rawElement('div', ['id' => 'yambe', 'class' => $printable ? [] : 'noprint'], $breadcrumb);
	}

	public function onEditFormPreloadText(&$text, $title)
	{
		// Since for a new page no parent relationship is available, assume the edit was started from a red-link on the parent page
		// and extract that page from the referer URL.
		$urlSplit = $this->config->get('YambeURLsplit');

		if ($urlSplit == '/') {
			$url = parse_url($_SERVER['HTTP_REFERER']);
			$parentPath = substr($url['path'], 1);
		} else {
			$parentPath = end(explode($urlSplit, $_SERVER['HTTP_REFERER']));
		}

		// Retrieve a possible present breadcrumb tag from the parent page, it is no error if that fails.
		// Don't insert a tag if the parent page does not contain a tag already, otherwise each new page
		// could start a new breadcrumb chain which might be annoying.
		// The URL might contain encoded characters, these must be decoded first.
		$parentPage = $this->pageStore->getPageByText(rawurldecode($parentPath));
		if (is_null($parentPage)) {
			return true;
		}
		$parentRevision = $this->revisionLookup->getRevisionByTitle($parentPage);
		if (is_null($parentRevision)) {
			return true;
		}
		$parentContent = $parentRevision->getContent("main", RevisionRecord::RAW);
		if (!($parentContent instanceof TextContent)) {
			return true;
		}
		$tag = $this->extractTag($parentContent->getText());
		if (is_null($tag)) {
			return true;
		}

		// Construct breadcrumb tag to parent page
		// TODO: Why is the 'self' attribute used as display name here? According to the documentation it should
		//       only be used for the final page. The usage of 'self' and display name could lead to the situation
		//       that the displayed page title is different depending on if it is the last element of the chain or not.
		$parentKey = $parentPage->getDBkey();
		$selfText = $tag->breadcrumb->attributes()->{'self'};
		if ($selfText != '') {
			$parentValue = $parentKey . '|' . $selfText;
		} else {
			$parentValue = $parentKey;
		}
		// Special characters must be escaped because extractTag() works with the unprocessed wikitext,
		// unescaped this could lead to invalid XML
		$text = Html::element("yambe:breadcrumb", [], $parentValue);

		return true;
	}


	private function extractTag($text)
	{
		// TODO: Poor attempt to extract the tag from the text, does only return the first found one
		$tagStart = strpos($text, "<yambe:breadcrumb");
		if ($tagStart !== false) {
			$tagEnd = strpos($text, "</yambe:breadcrumb>", $tagStart);
			if ($tagEnd !== false) {
				$tagEnd += 19;
			} else {
				$tagEnd = strpos($text, "/>", $tagStart);
				if ($tagEnd !== false) {
					$tagEnd += 2;
				}
			}
			if ($tagEnd !== false) {
				$tagValue = substr($text, $tagStart, $tagEnd - $tagStart);

				return new SimpleXMLElement(
					"<?xml version='1.0' standalone='yes'?><yambe:root xmlns:yambe='https://github.com/qtiger/yambe'>$tagValue</yambe:root>",
					0,
					false,
					'yambe',
					true
				);
			}
		}

		return null;
	}
}
