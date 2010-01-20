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

// Extension credits that will show up on Special:Version    
$wgExtensionCredits['parserhook'][] = array(
	'name'         => "WoWItem",
	'version'      => "0.10",
	'author'       => "James Twyford", 
	'url'          => "http://www.wowwiki.com",
	'description'  => "This extension handles World of Warcraft items",
);

//Avoid unstubbing $wgParser on setHook() too early on modern MW (1.12+) per r35980
if (defined('MW_SUPPORTS_PARSERFIRSTCALLINIT')) {
	$wgHooks['ParserFirstCallInit'][] = 'efWoWItemInit';
} else { // Otherwise do things the old fashioned way
	$wgExtensionFunctions[] = 'efWoWItemInit';
}
/**
 * global init--set up our hooks and callbacks
 *
 * @return true
 */
function efWoWItemInit()
{
	global $wgParser;
	$wgParser->setHook('item', 'WoWItem::parse');
	return true;
}

$wgAutoloadClasses['WoWItem'] = dirname(__FILE__) . '/WoWItem.body.php';
$wgAutoloadClasses['WoWItemParser'] = dirname(__FILE__) . '/WoWItem.parser.php';
$wgAutoloadClasses['WoWItemRender'] = dirname(__FILE__) . '/WoWItem.render.php';
