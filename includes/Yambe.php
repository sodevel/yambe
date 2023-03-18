<?php

namespace MediaWiki\Extension\Yambe;

use Config;
use Wikimedia\Rdbms\ILoadBalancer;
use MediaWiki\Linker\LinkRenderer;
use TitleParser;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\EditFormPreloadTextHook;
use Parser;
use TitleValue;
use MalformedTitleException;

use SimpleXMLElement;

class Yambe implements ParserFirstCallInitHook, EditFormPreloadTextHook
{

	private $config;
	private $loadBalancer;
	private $linkRenderer;
	private $titleParser;

	public function __construct(Config $config, ILoadBalancer $loadBalancer, LinkRenderer $linkRenderer, TitleParser $titleParser)
	{
		$this->config = $config;
		$this->loadBalancer = $loadBalancer;
		$this->linkRenderer = $linkRenderer;
		$this->titleParser = $titleParser;
	}

	public function onParserFirstCallInit($parser)
	{
		$parser->setHook('yambe:breadcrumb', [$this, 'renderBreadcrumb']);
	}

	public function renderBreadcrumb($input, array $args, Parser $parser)
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
				if (is_null($parentText)) {
					$parentText = '';
				}

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
					if (!$tag['exists']) {
						break;
					}
					$input = $tag['data'];
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
			if ($tag['exists']) {
				if ($tag['self'] != '') {
					$parentText = $tag['self'];
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
			$tag = $this->yambeUnpackTag($row->old_text);
		} else {
			$tag['exists'] = false;
			$tag['self'] = '';
			$tag['data'] = '';
		}

		return $tag;
	}

	// Bit of a kludge to get data and arguments from a yambe tag
	private function yambeUnpackTag($text)
	{
		$ret['exists'] = false;
		$ret['data'] = "";
		$ret['self'] = "";
		$end = false;

		// Find the opening tag in the supplied text
		$start = strpos($text, "<yambe:breadcrumb");

		// Find the end of the tag
		// Grab it and convert <yambe_breadcrumb> because simplexml doesn't like <yambe:breadcrumb>
		if ($start !== false) {
			$end = strpos($text, "</yambe:breadcrumb>", $start);
			if ($end !== false) $tag = substr($text, $start, $end - $start + 19);
			else {
				$end = strpos($text, "/>", $start);
				if ($end !== false) $tag = substr($text, $start, $end - $start + 2);
			}

			if ($end !== false) {
				$tag = str_replace("yambe:breadcrumb", "yambe_breadcrumb", $tag);

				// encapsulate in standalone XML doc
				$xmlstr = "<?xml version='1.0' standalone='yes'?><root>$tag</root>";

				$xml = new SimpleXMLElement($xmlstr);

				// And read the data out of it
				$ret['self'] = $xml->yambe_breadcrumb['self'];
				$ret['data'] = $xml->yambe_breadcrumb[0];
				$ret['exists'] = true;
			}
		}

		return $ret;
	}
}
