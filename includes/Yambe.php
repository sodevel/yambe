<?php

namespace MediaWiki\Extension\Yambe;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\EditFormPreloadTextHook;
use Config;
use Wikimedia\Rdbms\ILoadBalancer;
use TitleParser;
use MediaWiki\Linker\LinkRenderer;
use Parser;
use PPFrame;
use TitleValue;
use MalformedTitleException;
use SimpleXMLElement;

class Yambe implements ParserFirstCallInitHook, EditFormPreloadTextHook
{
	private $config;
	private $loadBalancer;
	private $titleParser;
	private $linkRenderer;


	public function __construct(Config $config, ILoadBalancer $loadBalancer, TitleParser $titleParser, LinkRenderer $linkRenderer)
	{
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
		$this->titleParser = $titleParser;
		$this->linkRenderer = $linkRenderer;
	}


	public function onParserFirstCallInit($parser)
	{
		$parser->setHook('yambe:breadcrumb', [$this, 'renderBreadcrumb']);
	}

	public function renderBreadcrumb($input, array $args, Parser $parser, PPFrame $frame)
	{
		$bcDelim = $this->config->get('YambeBCDelim');
		$maxCountBack = $this->config->get('YambeMaxCountBack');
		$overflowPre = $this->config->get('YambeOverflowPre');
		$selfLink = $this->config->get('YambeSelfLink');

		// TODO: This is bad, when do we really need to invalidate the page cache?
		$parser->getOutput()->updateCacheExpiry(0);

		$page = $parser->getPage();
		if (is_null($page)) {
			return '';
		}
		$selfTitle = TitleValue::newFromPage($page);
		if ($args['self'] != '') {
			$selfText = $args['self'];
		} else {
			$selfText = $selfTitle->getText();
		}

		// Breadcrumb is built in reverse and ends with this rather gratuitous self-link
		if ($selfLink) {
			$breadcrumb = $this->linkRenderer->makeKnownLink($selfTitle, $selfText);
		} else {
			$breadcrumb = $selfText;
		}

		// Store the current link details to prevent circular references
		$bcList = array();
		array_push($bcList, $selfTitle);

		try {
			for ($count = 0; $count < $maxCountBack; ) {
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

				$parentTitle = $this->titleParser->parseTitle($parentPath);
				// Check link not already in stored in list to prevent circular references
				foreach ($bcList as $element) {
					if ($parentTitle->isSameLinkAs($element)) {
						break 2;
					}
				}
				array_push($bcList, $parentTitle);
				// Don't add breadcrumbs to non-existent pages,
				// can't continue the breadcrumb chain if the parent page doesn't exist
				if (!$this->pageExists($parentTitle->getDBkey(), $parentTitle->getNamespace())) {
					break;
				}

				if (++$count < $maxCountBack) {
					$parentLink = $this->linkRenderer->makeKnownLink($parentTitle, $parentText);
					$breadcrumb = $parentLink . $bcDelim . $breadcrumb;

					$tag = $this->getTagFromPage($parentTitle->getDBkey(), $parentTitle->getNamespace());
					if (is_null($tag)) {
						break;
					}
					$input = $tag->breadcrumb;
				} else {
					$breadcrumb = $overflowPre . $bcDelim . $breadcrumb;
				}
			}
		} catch (MalformedTitleException) {
			// Ignore
		}

		// Encapsulate the final breadcrumb in its div and send it back to the parser
		return "<div id='yambe' class='noprint'>$breadcrumb</div>\n";
	}

	public function onEditFormPreloadText(&$text, $title)
	{
		// Since there is no parent relationship available, assume the edit was started from a red-link on the parent page
		// and extract that page from the referer URL.
		$urlSplit = $this->config->get('YambeURLSplit');

		if ($urlSplit == '/') {
			$url = parse_url($_SERVER['HTTP_REFERER']);
			$parentPath = substr($url['path'], 1);
		} else {
			$parentPath = end(explode($urlSplit, $_SERVER['HTTP_REFERER']));
		}

		try {
			$parentTitle = $this->titleParser->parseTitle($parentPath);
			$parentKey = $parentTitle->getDBkey();
			$parentNs = $parentTitle->getNamespace();
			$tag = $this->getTagFromPage($parentKey, $parentNs);
			if (!is_null($tag)) {
				$selfText = $tag->breadcrumb->attributes()->{'self'};
				if ($selfText != '') {
					$parentText = $selfText;
				} else {
					$parentText = $parentTitle->getText();
				}

				$text = "<yambe:breadcrumb>$parentKey|$parentText</yambe:breadcrumb>";
			}
		} catch (MalformedTitleException) {
			// Ignore
		}

		return true;
	}


	private function pageExists($pageKey, $pageNs = 0)
	{
		$db = $this->loadBalancer->getConnection(ILoadBalancer::DB_REPLICA);
		if (
			!$db->newSelectQueryBuilder()
				->select('page_id')
				->from('page')
				->where([
					'page_namespace' => $pageNs,
					'page_title' => $pageKey
				])
				->caller(__METHOD__)
				->fetchField()
		) {
			return false;
		}

		return true;
	}

	private function getTagFromPage($pageKey, $pageNs = 0)
	{
		$db = $this->loadBalancer->getConnection(ILoadBalancer::DB_REPLICA);
		$row = $db->newSelectQueryBuilder()
			->select('old_text')
			->from('text')
			->join('content', null, 'CONCAT(\'tt:\', old_id)=content_address')
			->join('slots', null, 'content_id=slot_content_id')
			->join('slot_roles', null, 'slot_role_id=role_id AND role_name=\'main\'')
			->join('revision', null, 'slot_revision_id=rev_id')
			->join('page', null, 'rev_id=page_latest')
			->where([
				'page_namespace' => $pageNs,
				'page_title' => $pageKey
			])
			->caller(__METHOD__)
			->fetchRow()
		;
		if ($row) {
			return $this->extractTag($row->old_text);
		}

		return null;
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
