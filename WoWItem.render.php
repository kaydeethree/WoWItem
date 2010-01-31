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
 * WoWItem render class
 *
 * @package MediaWiki
 * @author  James Twyford <jtwyford@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link    http://www.wowwiki.com WoWWiki
 */
class WoWItemRender
{
	/**
	 * an array of the 10 playable classes, in sort order
	 * (as defined in FrameXML/Constants.lua)
	 */
	public $class = array (
		'Warrior', 'Death Knight', 'Paladin', 'Priest', 'Shaman', 'Druid',
		'Rogue', 'Mage', 'Warlock', 'Hunter'
	);

	/**
	 * an array of the 12 playable races, alphabetic order
	 */
	public $races = array (
		'Blood Elf', 'Draenei', 'Dwarf', 'Gnome', 'Goblin', 'Human',
		'Night Elf', 'Orc', 'Tauren', 'Troll', 'Undead', 'Worgen'
	);
	/**
	 * an array of the 19 InventorySlotIDs (+extensions for display purposes)
	 */
	public $slots = array (
		-15 => 'Two-Hand', -16 => 'One-Hand', -17 => 'Held in off hand',
		-18 => 'Relic', -19 => 'Thrown', 0 => 'Projectile', 'Head', 'Neck',
		'Shoulder', 'Shirt', 'Chest', 'Waist', 'Legs', 'Feet', 'Wrist',
		'Hands', 'Finger', 'Finger', 'Trinket', 'Trinket', 'Back', 'Main Hand',
		'Off Hand', 'Ranged', 'Tabard'
	);
	/**
	 * an array of the 8 wow spell schools
	 */
	public $schools = array (
		1=>'Physical', 'Holy', 'Fire', 'Nature', 'Frost', 'Shadow', 'Arcane'
	);

	/**
	 * an array containing the 8 reputation levels
	 */
	public $replevels = array (
		1=>'Hated', 'Hostile', 'Unfriendly', 'Neutral', 'Friendly', 'Honored',
		'Revered', 'Exalted'
	);
	/**
	 * an array containing the 8 quality levels
	 */
	public $qualities = array (
		'Poor', 'Common', 'Uncommon', 'Rare', 'Epic', 'Legendary', 'Artifact',
		'Heirloom'
	);

	/**
	 * an array containing the 5 primary attributes
	 */
	public $attributes = array (
		'Strength', 'Agility', 'Stamina', 'Intellect', 'Spirit'
	);

	/**
	 * an array containing the 5 socket types
	 */
	public $sockets = array (
		'Meta', 'Red', 'Blue', 'Yellow', 'Prismatic'
	);

	/**
	 * render a wow item in a tooltip from a formatted array
	 *
	 * Display use-cases:
	 * own page (default. include the SMW metadata)
	 * {{lootbox|...|foo}}/{{:Foo}}/js
	 * {{item|foo}} -- [Foo] or {1em-high icon}[Foo] {{Itemlink}}/{{Iconlink}}
	 * {{itembox|...|foo}} -- {3em-high icon}[Foo] {{Itemiconlarge}}+Itemlink
	 * {{costitem|...|foo}} -- {1em-high icon} {{Itemicon}}+{{Itemcost}}
	 *
	 * @param array  $attr   an item ready to display as a formatted array
	 * @param object $parser the parser used to display the item
	 *
	 * @return string a parsed string ready to display
	 */
	public function renderDefault ($attr, $parser)
	{
		$title = $parser->GetTitle();
		// start putting together our string
		$ownPage = ($title == $attr['itempage']);
		if ($ownPage) { //primary use-case: the item's own page!
			$out = $this->_genSemanticData($attr) . //all of the smw metadata
				'<div style="font-size:1em;float:right;width:18em"';
		} else { //everywhere else: js tooltips, lootboxes, etc
			$out = '<div style="font-size:0.8em;width:18em"';
		}

		//mandatory options
		$out .= " class=\"itemtooltip\">\n{{Icon|" . $attr['icon'] . 
			((isset ($attr['stack'])) ? '|' . $attr['stack'] : '') . '|size=' .
			(($ownPage) ? '3' : '4') . "|float=right|margin=0}}\n" .
			"<ul>\n";
		$out .= $this->_li(
			'name', '{{Quality|' . $this->qualities[$attr['quality']] .
			'|' . $attr['itempage'] . '|' . $attr['name'] . '|tooltip=1}}'
		);

		/*
		 * checks from this point out must happen in the order that we want
		 * it to display, modulo the right-aligned items which need to appear
		 * before their left-aligned counterparts.
		 *
		 * we're using the $this->_li() method to wrap lines with a classed
		 * <li> tag. Remember: this is a render method. Parsing should've
		 * already happened!
		 */

		if (isset ($attr['heroic'])) { // bool
			$out .= $this->_li('bonus heroic', 'Heroic');
		}
		if (isset ($attr['conjured'])) { // bool
			$out .= $this->_li('conjured', '[[Conjured item|Conjured Item]]');
		}
		if (isset ($attr['holiday'])) { // required holiday FT
			$out .= $this->_li(
				'req reqHoliday', 'Requires [[' . $attr['holiday'] . ']]'
			);
		}
		if (isset ($attr['locationbind'])) { // location bind FT
			$out .= $this->_li('req reqZone', $attr['locationbind']);
		}
		if (isset ($attr['bind'])) { //item bind
			$out .= $this->_li('bind', $attr['bind']);
		}
		if (isset ($attr['questitem'])) { // bool
			$out .= $this->_li('qitem', '[[Quest item|Quest Item]]');
		}
		if (isset ($attr['unique'])) { //Unique --these 5 cases mutually exclusive
			$out .= $this->_li('unique', '[[Unique]]');
		} elseif (isset ($attr['uniqueN'])) { //Unique (100)
			$out .= $this->_li('uniqueN', '[[Unique]] (' . $attr['uniqueN'] . ')');
		} elseif (isset ($attr['uniqueEq'])) { //Unique-Equipped
			$out .= $this->_li('uniqueEq', '[[Unique-equipped|Unique-Equipped]]');
		} elseif (isset ($attr['uniqueEqN'])) { //Unique-Equipped (something) FT
			$out .= $this->_li(
				'uniqueEqN', '[[Unique-equipped|Unique-Equipped]] (' .
				$attr['uniqueEqN'] . ')'
			);
		} // else, not unique (nothing displayed)
		if (isset ($attr['glyph'])) { //major/minor
			$out .= $this->_li('glyph', $attr['glyph']);
		}
		if (isset ($attr['duration'])) { //item duration FT
			$out .= $this->_li(
				'duration', '[[Duration (item)|Duration]]: ' . $attr['duration']
			);
		}
		if (isset ($attr['qbegin'])) { // begins a quest FT
			$out .= $this->_li(
				'qbegin', '{{Quest|' . $attr['qbegin'] .
				'|This Item Begins a Quest}}'
			);
		}
		if (isset ($attr['type'])) { // type (axe, cloth, etc)
			$out .= ' <li class="type" style="float:right; clear:all">' .
				$attr['type'] . "</li>\n";
		}
		if (isset ($attr['slot'])) { // slot
			$out .= $this->_li('slot', '[[' . $this->slots[$attr['slot']] . ']]');
		}
		if (isset ($attr['weapon'])) { //hey, it's a weapon!
			$out .= ' <li class="speed" style="float:right">[[Speed (attack)' .
				'|Speed]] ' . $attr['speed'] . "</li>\n";
			if (isset ($attr['damageschool'])) {
				$school = $this->schools[$attr['damageschool']];
			}
			$out .= $this->_li(
				'damage', $attr['dmg'][0] . ' – ' . $attr['dmg'][1] .
				((isset ($attr['damageschool'])) ? 
					" [[Magic schools (WoW)#$school|$school]]" :
				'') . ' Damage'
			);
			if (isset ($attr['bonusdamage'])) { //hey, bonus damage!
				if (isset ($attr['bonusdamageschool'])) {
					$school = $this->schools[$attr['bonusdamageschool']];
				}
				$out .= $this->_li(
					'bonusDamage', '+' . $attr['bdmg'][0] . ' – ' .
					$attr['bdmg'][1] . ((isset ($attr['bonusdamageschool'])) ?
					" [[Magic schools (WoW)#$school|$school]]" : '') . ' Damage'
				);
			}
			$out .= $this->_li(
				'dps', '(' .$attr['dps'] . ' [[DPS|damage per second]])'
			);
			if (isset ($attr['feraldps'])) { 
				$out .= ' <li class="feralAP" title="Only applies for druids">(' .
					$attr['feraldps'] . ' [[Feral attack power|<span class="cc-' .
					'druid">feral attack power</span>]])' . "\n";
			}
		}
		if (isset ($attr['armor'])) { // basic armor
			$out .= $this->_li('armor', $attr['armor'] . ' [[Armor]]');
		}
		if (isset ($attr['block'])) { // block value on shields only
			$out .= $this->_li(
				'block', $attr['block'] . ' [[Block value|Block]]'
			);
		}

		foreach ($this->attributes as $at) { // str/agi/stam/int/spr
			if (isset($attr[strtolower($at)])) {
				$out .= $this->_li(
					"attrib attr$at", '+' .
					$attr[strtolower($at)] . " [[$at]]"
				);
			}
		}
		if (isset ($attr['resist'])) { //fire/nature/frost/shadow/arcane resist
			foreach ($attr['resist'] as $sch=>$resist) {
				$school = $this->schools[$sch];
				$out .= $this->_li(
					"resist res$school", "+$resist [[$school ".
					"resistance|$school Resistance]]"
				);
			}
		}
		if (isset ($attr['socketed'])) {
			foreach ($attr['socket'] as $sock) {
				$out .= $this->_li(
					"socket sock$sock",
					"[[File:UI-EmptySocket-$sock.png||link=$sock ".
					"socket]] [[$sock socket|$sock Socket]]"
				);
			}
			$out .= $this->_li(
				'socket sockBonus', '[[Socket bonus|Socket Bonus]]: ' .
				$attr['sockbonus']
			);
		}

		if (isset ($attr['durability'])) { 
			$out .= $this->_li(
				'durability', '[[Durability]] ' . $attr['durability'] .
				' / ' . $attr['durability']
			);
		}
		if (isset ($attr['locked'])) { //these 2 cases mutually exclusive
			$out .= $this->_li('locked', '[[Locked]]');
		} elseif (isset ($attr['lockpick'])) {
			$out .= $this->_li(
				'req reqLockpick', 'Requires [[Lockpicking]] (' .
				$attr['lockpick'] . ')'
			);
		}
		if (isset ($attr['bagtype'])) { //these 2 cases mutually exclusive
			$out .= $this->_li(
				'bag', $attr['bagslots'] . ' Slot [[' . $attr['bagtype'] . ']]'
			);
		} elseif (isset ($attr['bagslots'])) { //just a regular bag
			$out .= $this->_li('bag', $attr['bagslots'] . ' Slot [[Bag]]');
		}
		if (isset ($attr['class'])) { // class
			$class = '';
			foreach ($attr['class'] as $k=>$v) {
				$class .= $this->class[$k] . ', ';
			}
			$out .= $this->_li(
				'req reqClass', '[[Class|Classes]]: ' . substr($class, 0, -2)
			);
		}
		if (isset ($attr['race'])) { // race
			$race = '';
			foreach ($attr['race'] as $k=>$v) {
				$race .= $this->races[$k] . ', ';
			}
			$out .= $this->_li(
				'req reqRace', '[[Race|Races]]: ' . substr($race, 0, -2)
			);
		}
		if (isset ($attr['level'])) { // level
			$out .= $this->_li('req Level', 'Requires Level ' . $attr['level']);
		}
		if (isset ($attr['ilvl'])) { // item level (REQUIRED)
			$out .= $this->_li(
				'iLvl', '[[Item level|Item Level]] ' . $attr['ilvl']
			);
		}
		if (isset ($attr['subskill'])) { // skill specialization
			$out .= $this->_li(
				'req reqSubskill', 'Requires [[' . $attr['subskill'] . ']]'
			);
		}
		if (isset ($attr['profrequired'])) { // skill rating
			$out .= $this->_li(
				'req reqSkill', 'Requires [[' . $attr['skill'] . ']] (' .
				$attr['skillrating'] . ')'
			);
		}
		if (isset ($attr['reprequired'])) { // reputation FT
			$out .= $this->_li(
				'req reqRep', 'Requires [[' . $attr['faction'] . ']] – [[' .
				$this->replevels[$attr['factionrating']] . ']]'
			);
		}
		if (isset ($attr['arena'])) {
			$out .= $this->_li(
				'req reqArena', 'Requires [[Arena personal rating|' .
				'personal]] and [[Arena team rating|team]] arena rating of ' .
				$attr['arena']
			);
		}
		if (isset ($attr['onhit'])) { // bonus chance on hit
			foreach ($attr['onhit'] as $onhit) {
				$out .= $this->_li('bonus onhit', "Chance on hit: $onhit");
			}
		}

		//hard-coded bonuses
		if (isset ($attr['defense'])) {
			$out .= $this->_li(
				'bonus defense', 'Equip: Increases [[defense rating]] by ' .
				$attr['defense'] . '.'
			);
		}
		if (isset ($attr['dodge'])) {
			$out .= $this->_li(
				'bonus dodge', 'Equip: Increases your [[dodge rating]] by ' .
				$attr['dodge'] . '.'
			);
		}
		if (isset ($attr['parry'])) {
			$out .= $this->_li(
				'bonus parry', 'Equip: Increases your [[parry rating]] by ' .
				$attr['parry'] . '.'
			);
		}
		if (isset ($attr['blockrating'])) {
			$out .= $this->_li(
				'bonus block', 'Equip: Increases your shield [[block rating]] by ' .
				$attr['blockrating'] . '.'
			);
		}
		if (isset ($attr['haste'])) {
			$out .= $this->_li(
				'bonus haste', 'Equip: Improves [[haste rating]] by ' .
				$attr['haste'] . '.'
			);
		}
		if (isset ($attr['hit'])) {
			$out .= $this->_li(
				'bonus hit', 'Equip: Improves [[hit rating]] by ' .
				$attr['hit'] . '.'
			);
		}
		if (isset ($attr['crit'])) {
			$out .= $this->_li(
				'bonus crit', 'Equip: Improves [[critical strike rating]] by ' .
				$attr['crit'] . '.'
			);
		}
		if (isset ($attr['resilience'])) {
			$out .= $this->_li(
				'bonus resil', 'Equip: Improves [[resilience rating]] by ' .
				$attr['resilience'] . '.'
			);
		}
		if (isset ($attr['expertise'])) {
			$out .= $this->_li(
				'bonus exp', 'Equip: Increases your [[expertise rating]] by ' .
				$attr['expertise'] . '.'
			);
		}
		if (isset ($attr['ap'])) {
			$out .= $this->_li(
				'bonus ap', 'Equip: Increases [[attack power]] by ' .
				$attr['ap'] . '.'
			);
		}
		if (isset ($attr['mp5'])) {
			$out .= $this->_li(
				'bonus mp5', 'Equip: Restores ' . $attr['mp5'] .
				' [[MP5|mana per 5]] sec.'
			);
		}
		if (isset ($attr['arp'])) {
			$out .= $this->_li(
				'bonus arp', 'Equip: Increases your [[armor penetration ' .
				'rating]] by ' . $attr['arp'] . '.'
			);
		}
		if (isset ($attr['spellpower'])) {
			$out .= $this->_li(
				'bonus sp', 'Equip: Increases [[spell power]] by ' .
				$attr['spellpower'] . '.'
			);
		}

		if (isset ($attr['equip'])) { // other bonus equip effects FT
			foreach ($attr['equip'] as $equip) {
				$out .= $this->_li('bonus equip', "Equip: $equip");
			}
		}
		if (isset ($attr['use'])) { // other bonus use effects FT
			foreach ($attr['use'] as $use) {
				$out .= $this->_li('bonus use', "Use: $use");
			}
		}
		if (isset ($attr['recipe'])) { // recipe that creates another item
			$out .= $this->_li(
				'create', '{{Loot|' . $this->qualities[$attr['createq']] .
				'|' . $attr['create'] . '}}'
			);
			$out .= $this->_li('reagents', 'Requires ' . $attr['reagents']);
		}
		if (isset ($attr['charges']) && is_numeric($attr['charges'])) {
			$out .= $this->_li('charges', $attr['charges'] . ' [[Charges]]');
		}
		if (isset ($attr['flavor'])) { // FT
			$out .= $this->_li('flavor', '"' . $attr['flavor'] . '"');
		}
		if (isset ($attr['read'])) { 
			$out .= $this->_li('bonus read', '<Right Click to Read>');
		}
		if (isset ($attr['open'])) { 
			$out .= $this->_li('bonus open', '<Right Click to Open>');
		}
		if (isset ($attr['setpiece'])) {
			if ($ownPage) {
				$out .= ' {{:' . $attr['setpage'] . '|' . $attr['set'] .
					"|mode=itemtip}}\n";
			} else {
				$out .= $this->_li(
					'set', '[[' . $attr['setpage'] . '|' . $attr['set'] .
					']] (1/' . $attr['setpieces'] . ')'
				);
			}
		}
		if (isset ($attr['sell'])) { // FT
			$out .= $this->_li('sell', 'Sell Price: ' . $attr['sell']);
		}

		//finish the string
		$out .= "</ul></div>\n";

		//to the wiki!
		return $parser->recursiveTagParse($out);

	}

	/**
	 * quick wrapper function to enclose content in a classed <li> element
	 *
	 * @param string $class   the <li> class
	 * @param string $content any displayable comment
	 *
	 * @return string formatted with an <li> tag.
	 */
	private function _li($class, $content)
	{
		return " <li class=\"$class\">$content</li>\n";
	}

	/**
	 * generate all of the semantic mediawiki metadata
	 *
	 * @param array $attr the array to generate the metadata from
	 *
	 * @return string a very long {{#set:key=val|key2=val2...}} line
	 */
	private function _genSemanticData($attr)
	{
		$out = '{{#set:Name=' . $attr['name'] . '|Item page=' .
			$attr['itempage'] . '|Quality=' .
			$this->qualities[$attr['quality']] . '|Icon=' . $attr['icon'] .
			'|ID=' . $attr['id'];

		if (isset ($attr['heroic'])) {
			$out .= '|Heroic=true';
		}
		if (isset ($attr['conjured'])) {
			$out .= '|Conjured=true';
		}
		if (isset ($attr['holiday'])) {
			$out .= '|Requires holiday=' . $attr['holiday'];
		}
		if (isset ($attr['locationbind'])) {
			$out .= '|Requires zone=' . $attr['locationbind'];
		}
		if (isset ($attr['bind'])) {
			$bind = explode('|', $attr['bind']);
			$out .= '|Bind type=' .substr($bind[0], 2);
		}
		if (isset ($attr['questitem'])) {
			$out .= '|Quest item=true';
		}
		if (isset ($attr['unique'])) {
			$out .= '|Unique=true';
		} elseif (isset ($attr['uniqueN'])) {
			$out .= '|Max quantity=' . $attr['uniqueN'];
		} elseif (isset ($attr['uniqueEq'])) {
			$out .= '|Unique-equipped=true';
		} elseif (isset ($attr['uniqueEqN'])) {
			$out .= '|Max equipped=' . $attr['uniqueEqN'];
		}
		if (isset ($attr['glyph'])) {
			$glyph = explode('|', $attr['glyph']);
			$out .= '|Glyph type=' . substr($glyph[0], 2);
		}
		if (isset ($attr['duration'])) {
			$out .= '|Limited duration=' . $attr['duration'];
		}
		if (isset ($attr['qbegin'])) {
			$out .= '|Begins a quest=true|Quest begin=' . $attr['qbegin'];
		}
		if (isset ($attr['type'])) {
			$type = explode('|', $attr['type']);
			$type[1] = (isset($type[1]) ? substr($type[1], 0, -2) : false);
			$out .= '|Equipment type=' . substr($type[0], 2);
		}
		if (isset ($attr['slot'])) {
			$out .= '|Slot=' . $this->slots[$attr['slot']];
		}
		if (isset ($attr['weapon'])) {
			$out .= '|Speed=' . $attr['speed'] . '|Low damage=' .
				$attr['dmg'][0] . '|High damage=' . $attr['dmg'][1] .
				'|Damage per second=' . $attr['dps'];
			if (isset ($attr['damageschool'])) {
				$out .= '|Damage school=' .
					$this->schools[$attr['damageschool']];
			}
			if (isset ($attr['bonusdamage'])) {
				$out .= '|Bonus low damage=' .$attr['bdmg'][0] .
					'|Bonus high damage=' . $attr['bdmg'][1];
				if (isset ($attr['bonusdamageschool'])) {
					$out .= '|Bonus damage school=' .
						$this->schools[$attr['bonusdamageschool']];
				}
			}
		}
		if (isset ($attr['armor'])) {
			$out .= '|Armor=' . $attr['armor'];
		}
		if (isset ($attr['block'])) {
			$out .= '|Block=' . $attr['block'];
		}
		foreach ($this->attributes as $attrib) {
			if (isset ($attr[strtolower($attrib)])) {
				$out .= "|$attrib=" . $attr[strtolower($attrib)];
			}
		}
		if (isset ($attr['resist'])) {
			foreach ($attr['resist'] as $sch=>$resist) {
				$school = $this->schools[$sch];
				$out .= "|$school resistance=" . $resist;
			}
		}
		if (isset ($attr['socketed'])) {
			$Meta=0; $Red=0; $Blue=0; $Yellow=0; $Prismatic=0;
			foreach ($attr['socket'] as $sock) {
				$$sock++;
			}
			foreach ($this->sockets as $sock) {
				if ($$sock > 0) {
					$out .= "|$sock sockets=" . $$sock;
				}
			}
			$out .='|Socket bonus=' .$attr['sockbonus'];
		}
		if (isset ($attr['durability'])) {
			$out .= '|Durability=' . $attr['durability'];
		}
		if (isset ($attr['locked'])) {
			$out .= '|Locked=true';
		} elseif (isset ($attr['lockpick'])) {
			$out .= '|Requires lockpicking=' . $attr['lockpick'];
		}
		if (isset ($attr['bagtype'])) {
			$out .= '|Specialty bag=' . $attr['bagtype'];
		}
		if (isset ($attr['bagslots'])) {
			$out .= '|Bag slots=' . $attr['bagslots'];
		}
		if (isset ($attr['class'])) {
			foreach ($attr['class'] as $k=>$v) {
				$class = $this->class[$k];
				$out .= "|Requires class=$class";
			}
		}
		if (isset ($attr['race'])) {
			foreach ($attr['race'] as $k=>$v) {
				$race = $this->races[$k];
				$out .= "|Requires race=$race";
			}
		}
		if (isset ($attr['level'])) {
			$out .= '|Requires level=' . $attr['level'];
		}
		if (isset ($attr['ilvl'])) {
			$out .= '|Item level=' . $attr['ilvl'];
		}
		if (isset ($attr['subskill'])) {
			$out .= '|Requires specialization=' . $attr['subskill'];
		}
		if (isset ($attr['profrequired'])) {
			$out .= '|Requires profession=' . $attr['skill'] .
				'|Requires profession skill=' . $attr['skillrating'];
		}
		if (isset ($attr['reprequired'])) {
			$out .= '|Requires reputation=' . $attr['faction'] .
				'|Requires reputation rating=' .
				$this->replevels[$attr['factionrating']];
		}
		if (isset ($attr['arena'])) {
			$out .= '|Requires arena rating=' . $attr['arena'];
		}
		if (isset ($attr['onhit'])) { //there should only be 1
			foreach ($attr['onhit'] as $onhit) {
				$out .= "|Chance on hit=$onhit";
			}
		}
		//hard-coded bonuses
		if (isset ($attr['defense'])) {
			$out .= '|Defense rating=' . $attr['defense'];
		}
		if (isset ($attr['dodge'])) {
			$out .= '|Dodge rating=' . $attr['dodge'];
		}
		if (isset ($attr['parry'])) {
			$out .= '|Parry rating=' . $attr['parry'];
		}
		if (isset ($attr['blockrating'])) {
			$out .= '|Block rating=' . $attr['blockrating'];
		}
		if (isset ($attr['haste'])) {
			$out .= '|Haste rating=' . $attr['haste'];
		}
		if (isset ($attr['hit'])) {
			$out .= '|Hit rating=' . $attr['hit'];
		}
		if (isset ($attr['crit'])) {
			$out .= '|Critical strike rating=' . $attr['crit'];
		}
		if (isset ($attr['resilience'])) {
			$out .= '|Resilience rating=' . $attr['resilience'];
		}
		if (isset ($attr['expertise'])) {
			$out .= '|Expertise rating=' . $attr['expertise'];
		}
		if (isset ($attr['ap'])) {
			$out .= '|Attack power=' . $attr['ap'];
		}
		if (isset ($attr['mp5'])) {
			$out .= '|Mana regeneration=' . $attr['mp5'];
		}
		if (isset ($attr['arp'])) {
			$out .= '|Armor penetration rating=' . $attr['arp'];
		}
		if (isset ($attr['spellpower'])) {
			$out .= '|Spell power=' . $attr['spellpower'];
		}
		if (isset ($attr['equip'])) { //other equip lines
			foreach ($attr['equip'] as $equip) {
				$out .= "|Equip=$equip";
			}
		}
		if (isset ($attr['use'])) { //there should only be 1
			foreach ($attr['use'] as $use) {
				$out .= "|Use=$use";
			}
		}
		if (isset ($attr['recipe'])) {
			$out .= '|Is recipe=true|Creates=' . $attr['create'] .
			'|Create quality=' . $this->qualities[$attr['createq']] .
			'|Reagents=' . $attr['reagents'];
		}
		if (isset ($attr['charges'])) {
			$out .= '|Charges=' . $attr['charges'];
		}
		if (isset ($attr['flavor'])) {
			$out .= '|Flavor=' . $attr['flavor'];
		}
		if (isset ($attr['read'])) {
			$out .= '|Readable=true';
		}
		if (isset ($attr['open'])) {
			$out .= '|Openable=true';
		}
		if (isset ($attr['setpiece'])) {
			$out .= '|In set' . $attr['set'] . '|Set page=' .
				$attr['setpage'] . '|Set pieces=' . $attr['setpieces'];
		}
		if (isset ($attr['sell'])) {
			'|Sell price=' . $attr['sell'];
		}
		return "$out}} [[Category:Item pages|" . $attr['itempage'] . "]]";
	}

}
