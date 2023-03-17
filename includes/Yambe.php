<?php

namespace MediaWiki\Extension\Yambe;

use Config;
use Wikimedia\Rdbms\ILoadBalancer;
use MediaWiki\Linker\LinkRenderer;
use TitleParser;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Hook\EditFormPreloadTextHook;
use Parser;
use MalformedTitleException;

use SimpleXMLElement;
use Title;

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

		$parser->getOutput()->updateCacheExpiry(0);
		$pgTitle = $parser->getTitle();

		// Grab the self argument if it exists
		if (isset($args['self'])) $yambeSelf = $args['self'];
		else $yambeSelf = $pgTitle->getText();

		// Breadcrumb is built in reverse and ends with this rather gratuitous self-link
		if ($selfLink) $breadcrumb = $this->linkFromText($pgTitle->getText(), $yambeSelf, $pgTitle->getNamespace());
		else $breadcrumb = $yambeSelf;

		$cur = str_replace(" ", "_", ($pgTitle->getText()));

		// Store the current link details to prevent circular references
		if ($pgTitle->getNsText() == "Main") $bcList[$cur] = "";
		else $bcList[$cur] = $pgTitle->getNsText();

		if ($input != "") {
			$cont = true;
			$count = 2; // because by first check, breadcrumb will have 2 elements!

			do {
				// Grab the parent information from the tag
				$parent = explode("|", $input);
				$page   = $this->splitName(trim($parent[0]));

				// Allow for use of only the parent page, no display text
				if (count($parent) < 2) $parent[1] = "";

				// Check link not already in stored in list to prevent circular references
				if (array_key_exists($page['title'], $bcList))
					if ($bcList[$page['title']] == $page['namespace']) $cont = false;

				if ($cont) {
					// Store the current link details to prevent circular references
					$bcList[str_replace(" ", "_", $page['title'])] = $page['namespace'];

					// make a url from the parent
					$url = $this->yambeMakeURL($page, trim($parent[1]));

					// And if valid add to the front of the breadcrumb
					if ($url != "") {
						$breadcrumb = $url . $bcDelim . $breadcrumb;

						// Get the next parent from the database
						$par = $this->getTagFromPage($page['title'], $page['namespaceid']);

						// Check to see if we've tracked back too far
						if ($count >= $maxCountBack) {
							$cont = false;
							if ($par['data'] != "")
								$breadcrumb = $overflowPre . $bcDelim . $breadcrumb;
						} else {
							$page['title'] = str_replace(" ", "_", $page['title']);

							$input = $par['data'];
							if ($input == "")
								$cont = false;
						}
					}
				}
				$count++;
			} while ($cont); // Loop back to get next parent
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


	// Function to get namespace id from name
	private function getNamespaceID($namespace)
	{
		if ($namespace == "") return 0;
		else {
			$ns = new MWNamespace();
			return $ns->getCanonicalIndex(trim(strtolower($namespace)));
		}
	}

	// Function to build a url from text
	private function linkFromText($page, $displayText, $nsID = 0)
	{
		$title = Title::newFromText(trim($page), $nsID);
		if (!is_null($title)) {
			return $this->linkRenderer->makeKnownLink($title, $displayText);
		}

		return "";
	}

	private function pageExists($page, $nsID = 0)
	{
		$page = str_replace(" ", "_", $page);

		$db = $this->loadBalancer->getConnection(ILoadBalancer::DB_REPLICA);

		if (
			!$db->newSelectQueryBuilder()
				->select('page_id')
				->from('page')
				->where([
					"page_namespace" => $nsID,
					"page_title" => $page
				])
				->caller(__METHOD__)
				->fetchField()
		) {
			return false;
		}

		return true;
	}

	// Function checks that the parent page exists and if so builds a link to it
	private function yambeMakeURL($page, $display)
	{
		if ($this->pageExists($page['title'], $page['namespaceid']))
			return $this->linkFromText($page['title'], $display, $page['namespaceid']);
		else return "";
	}

	// Get the parents tag
	private function getTagFromPage($pgName, $ns = 0)
	{
		$par['data'] = "";
		$par['exists'] = false;
		$par['self'] = "";

		$db = $this->loadBalancer->getConnection(ILoadBalancer::DB_REPLICA);

		$pgName = str_replace(" ", "_", $pgName);

		$res = $db->newSelectQueryBuilder()
			->select('old_text')
			->from('text')
			->join('content', null, 'CONCAT(\'tt:\', old_id)=content_address')
			->join('slots', null, 'content_id=slot_content_id')
			->join('slot_roles', null, 'slot_role_id=role_id AND role_name=\'main\'')
			->join('revision', null, 'slot_revision_id=rev_id')
			->join('page', null, 'rev_id=page_latest')
			->where([
				"page_namespace" => $ns,
				"page_title" => $pgName
			])
			->caller(__METHOD__)
			->fetchRow();

		if ($res) {
			$par = $this->yambeUnpackTag($res->old_text);
		}

		return $par;
	}

	private function splitName($in)
	{
		if (substr_count($in, ":")) {
			// Parent name includes Namespace - grab the page name out for display element
			$fullName  = explode(":", $in);
			$page['title']     = str_replace(" ", "_", $fullName[1]);
			$page['namespace'] = $fullName[0];
			$page['namespaceid'] = $this->getNamespaceID($fullName[0]);
		} else {
			$page['title']     = str_replace(" ", "_", $in);
			$page['namespace'] = "";
			$page['namespaceid'] = 0;
		}
		return $page;
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
