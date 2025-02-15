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
		// Used to store all visited pages to prevent circular references, this includes the current page
		$bcList = array();

		$selfTitle = TitleValue::newFromPage($page);
		$selfText = null;
		if (array_key_exists('self', $args)) {
			if ($args['self'] != '') {
				$selfText = $args['self'];
			}
		} else {
			$selfText = $selfTitle->getText();
		}
		array_push($bcList, $selfTitle);

		// The breadcrumbs are build in reverse order and start with the current page
		$breadcrumb = null;
		if (!is_null($selfText)) {
			if ($selfLink) {
				$breadcrumb = $linkRenderer->makeKnownLink($selfTitle, $selfText);
			} else {
				$breadcrumb = $selfText;
			}
		}

		// Build the breadcrumb chain by following the parent pages
		for ($count = is_null($selfText) ? 0 : 1; $count < $maxCount;) {
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

			// Check if the parent page is valid, this does not check if the parent page does exist.
			// The parent page does not need to exist, the link dependency will invalide the cache
			// if the page existence changes. A circular dependency must be prevented in any case.
			$parentPage = $this->pageStore->getPageByText($parentPath);
			if (is_null($parentPage)) {
				$breadcrumb = implode($delimiter, array_filter(["#INVALID: $parentPath#", $breadcrumb], fn ($value) => !is_null($value)));
				break;
			}
			$parentTitle = TitleValue::newFromPage($parentPage);
			foreach ($bcList as $element) {
				if ($parentTitle->isSameLinkAs($element)) {
					$breadcrumb = implode($delimiter, array_filter(["#CIRCULAR: $parentPath#", $breadcrumb], fn ($value) => !is_null($value)));
					break 2;
				}
			}
			array_push($bcList, $parentTitle);

			if (++$count < $maxCount) {
				// Extend the breadcrumb chain with the parent page, register the created link as dependency
				$parentLink = $parentPage->exists() ? $linkRenderer->makeKnownLink($parentTitle, $parentText) : $linkRenderer->makeBrokenLink($parentTitle, $parentText);
				$breadcrumb = implode($delimiter, array_filter([$parentLink, $breadcrumb], fn ($value) => !is_null($value)));
				$parser->getOutput()->addLink($parentTitle, $parentPage->getId());

				// Retrieve the contents of the parent page to extract a possible present breadcrumb tag,
				// the chain cannot continue if no tag is present
				$parentRevision = $this->revisionLookup->getRevisionByTitle($parentPage);
				if (is_null($parentRevision)) {
					break;
				}
				$parentContent = $parentRevision->getContent('main', RevisionRecord::RAW);
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
				$breadcrumb = implode($delimiter, array_filter([$overflowPrefix, $breadcrumb], fn ($value) => !is_null($value)));
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
		// The URL might contain encoded characters, these must be decoded first.
		$parentPath = rawurldecode($parentPath);

		// Retrieve a possible present breadcrumb tag from the parent page, it is no error if that fails.
		// Don't insert a tag if the parent page does not contain a tag already, otherwise each new page
		// could start a new breadcrumb chain which might be annoying.
		$parentPage = $this->pageStore->getPageByText($parentPath);
		if (is_null($parentPage)) {
			return true;
		}
		$parentRevision = $this->revisionLookup->getRevisionByTitle($parentPage);
		if (is_null($parentRevision)) {
			return true;
		}
		$parentContent = $parentRevision->getContent('main', RevisionRecord::RAW);
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
		$selfText = $tag->breadcrumb->attributes()->{'self'};
		if ($selfText != '') {
			$parentValue = $parentPath . '|' . $selfText;
		} else {
			$parentValue = $parentPath;
		}
		// Special characters must be escaped because extractTag() works with the unprocessed wikitext,
		// unescaped this could lead to invalid XML
		$text = Html::element('yambe:breadcrumb', [], $parentValue);

		return true;
	}


	private function extractTag($text)
	{
		// TODO: Poor attempt to extract the tag from the text, does only return the first found one
		$tagStart = strpos($text, '<yambe:breadcrumb');
		if ($tagStart !== false) {
			$tagEnd = strpos($text, '</yambe:breadcrumb>', $tagStart);
			if ($tagEnd !== false) {
				$tagEnd += 19;
			} else {
				$tagEnd = strpos($text, '/>', $tagStart);
				if ($tagEnd !== false) {
					$tagEnd += 2;
				}
			}
			if ($tagEnd !== false) {
				$tagValue = substr($text, $tagStart, $tagEnd - $tagStart);

				return new SimpleXMLElement(
					"<?xml version='1.0' standalone='yes'?><yambe:root xmlns:yambe='https://github.com/sodevel/yambe'>$tagValue</yambe:root>",
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
