<?php

namespace MediaWiki\Extension\Yambe;

use Config;
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

		// Output nothing if maximum count is zero, this effectively disables the extension output
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

		// Breadcrumb is built in reverse and ends with this rather gratuitous self-link
		if ($selfLink) {
			$breadcrumb = $linkRenderer->makeKnownLink($selfTitle, $selfText);
		} else {
			$breadcrumb = $selfText;
		}

		// Store the current link details to prevent circular references
		$bcList = array();
		array_push($bcList, $selfTitle);

		for ($count = 1; $count < $maxCount; ) {
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

			// Don't add breadcrumbs to non-existent pages,
			// can't continue the breadcrumb chain if the parent page doesn't exist
			$parentPage = $this->pageStore->getPageByText($parentPath);
			if (is_null($parentPage)) {
				break;
			}
			$parentTitle = TitleValue::newFromPage($parentPage);
			// Check link not already in stored in list to prevent circular references
			foreach ($bcList as $element) {
				if ($parentTitle->isSameLinkAs($element)) {
					break 2;
				}
			}
			array_push($bcList, $parentTitle);

			if (++$count < $maxCount) {
				$parentLink = $linkRenderer->makeKnownLink($parentTitle, $parentText);
				$breadcrumb = $parentLink . $delimiter . $breadcrumb;

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
				$breadcrumb = $overflowPrefix . $delimiter . $breadcrumb;
			}
		}

		// Encapsulate the final breadcrumb in its div and send it back to the parser
		return "<div id='yambe' class='noprint'>$breadcrumb</div>\n";
	}

	public function onEditFormPreloadText(&$text, $title)
	{
		// Since there is no parent relationship available, assume the edit was started from a red-link on the parent page
		// and extract that page from the referer URL.
		$urlSplit = $this->config->get('YambeURLsplit');

		if ($urlSplit == '/') {
			$url = parse_url($_SERVER['HTTP_REFERER']);
			$parentPath = substr($url['path'], 1);
		} else {
			$parentPath = end(explode($urlSplit, $_SERVER['HTTP_REFERER']));
		}

		$parentPage = $this->pageStore->getPageByText($parentPath);
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

		$parentKey = $parentPage->getDBkey();
		$selfText = $tag->breadcrumb->attributes()->{'self'};
		if ($selfText != '') {
			$parentValue = $parentKey . '|' . $selfText;
		} else {
			$parentValue = $parentKey;
		}
		$text = "<yambe:breadcrumb>$parentValue</yambe:breadcrumb>";

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
