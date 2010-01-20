<?php
/**
 * WoWItem - this extension handles World of Warcraft items
 *
 * To activate this extension, add the following into your LocalSettings.php file:
 * <code>require_once("$IP/extensions/WoWItem/WoWItem.setup.php");</code>
 *
 * @package    MediaWiki
 * @subpackage ParserHook
 * @author     James Twyford <jtwyford@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @version    v0.10
 * @link       http://www.wowwiki.com WoWWiki
 */

/**
 * Protect against register_globals vulnerabilities.
 * This line must be present before any global variable is referenced.
 */
if (!defined('MEDIAWIKI')) {
	echo("This is a MediaWiki extension, not a standalone PHP script.\n");
	die(-1);
}

/**
 * WoWItem main class: db connections, callbacks for all global hooks
 *
 * @package MediaWiki
 * @author  James Twyford <jtwyford@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link    http://www.wowwiki.com WoWWiki
 */
class WoWItem
{
	/**
	 * parse an item, stick it in the DB and render the default tooltip
	 *
	 * <item>
	 *
	 * @param string $input  any content between <item>foo</item> tags
	 * @param array  $args   parameters to the <item foo=bar> opening tag
	 * @param object $parser the global parser used to render
	 *
	 * @return string the default tooltip ready to display
	 */
	public static function parse($input, $args, $parser) 
	{
		//parse!
		$p = new WoWItemParser();
		$item = $p->parse($input, $args, $parser);
		//any errors?
		if (!is_array($item)) {
			return $parser->recursiveTagParse($item);
		}

		//set up our renderer
		$r = new WoWItemRender();

		//render default tooltip
		$render = $r->renderDefault($item, $parser);
		return $render;
	}
}

