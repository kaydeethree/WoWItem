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
			$out = $this->_SMWIze($attr) . //all of the smw metadata
				'<div style="font-size:1em;float:right;width:18em"';
		} else { //everywhere else: js tooltips, lootboxes, etc
			$out = '<div style="font-size:0.8em;width:18em"';
		}

		//kd3 is lazy. $a is quicker to type than array_key_exists.
		$a = 'array_key_exists';

		//mandatory options
		$out .= " class=\"itemtooltip\">\n{{Icon|" . $attr['icon'] . 
			(($a('stack', $attr)) ? '|' . $attr['stack'] : '') . '|size=' .
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

		if ($a('heroic', $attr)) { // bool
			$out .= $this->_li('bonus heroic', 'Heroic');
		}
		if ($a('conjured', $attr)) { // bool
			$out .= $this->_li('conjured', '[[Conjured item|Conjured Item]]');
		}
		if ($a('holiday', $attr)) { // required holiday FT
			$out .= $this->_li(
				'req reqHoliday', 'Requires [[' . $attr['holiday'] . ']]'
			);
		}
		if ($a('locationbind', $attr)) { // location bind FT
			$out .= $this->_li('req reqZone', $attr['locationbind']);
		}
		if ($a('bind', $attr)) { //item bind
			$out .= $this->_li('bind', $attr['bind']);
		}
		if ($a('questitem', $attr)) { // bool
			$out .= $this->_li('qitem', '[[Quest item|Quest Item]]');
		}
		if ($a('unique', $attr)) { //Unique --these 5 cases mutually exclusive
			$out .= $this->_li('unique', '[[Unique]]');
		} elseif ($a('uniqueN', $attr)) { //Unique (100)
			$out .= $this->_li('uniqueN', '[[Unique]] (' . $attr['uniqueN'] . ')');
		} elseif ($a('uniqueEq', $attr)) { //Unique-Equipped
			$out .= $this->_li('uniqueEq', '[[Unique-equipped|Unique-Equipped]]');
		} elseif ($a('uniqueEqN', $attr)) { //Unique-Equipped (something) FT
			$out .= $this->_li(
				'uniqueEqN', '[[Unique-equipped|Unique-Equipped]] (' .
				$attr['uniqueEqN'] . ')'
			);
		} // else, not unique (nothing displayed)
		if ($a('glyph', $attr)) { //major/minor
			$out .= $this->_li('glyph', $attr['glyph']);
		}
		if ($a('duration', $attr)) { //item duration FT
			$out .= $this->_li(
				'duration', '[[Duration (item)|Duration]]: ' . $attr['duration']
			);
		}
		if ($a('qbegin', $attr)) { // begins a quest FT
			$out .= $this->_li(
				'qbegin', '{{Quest|' . $attr['qbegin'] .
				'|This Item Begins a Quest}}'
			);
		}
		if ($a('type', $attr)) { // type (axe, cloth, etc)
			$out .= ' <li class="type" style="float:right; clear:all">' .
				$attr['type'] . "</li>\n";
		}
		if ($a('slot', $attr)) { // slot
			$out .= $this->_li('slot', '[[' . $this->slots[$attr['slot']] . ']]');
		}
		if ($a('weapon', $attr)) { //hey, it's a weapon!
			$out .= ' <li class="speed" style="float:right">[[Speed (attack)' .
				'|Speed]] ' . $attr['speed'] . "</li>\n";
			if ($a('damageschool', $attr)) {
				$school = $this->schools[$attr['damageschool']];
			}
			$out .= $this->_li(
				'damage', $attr['dmg'][0] . ' – ' . $attr['dmg'][1] .
				(($a('damageschool', $attr)) ? 
					" [[Magic schools (WoW)#$school|$school]]" :
				'') . ' Damage'
			);
			if ($a('bonusdamage', $attr)) { //hey, bonus damage!
				if ($a('bonusdamageschool', $attr)) {
					$school = $this->schools[$attr['bonusdamageschool']];
				}
				$out .= $this->_li(
					'bonusDamage', '+' . $attr['bdmg'][0] . ' – ' .
					$attr['bdmg'][1] . (($a('bonusdamageschool', $attr)) ?
					" [[Magic schools (WoW)#$school|$school]]" : '') . ' Damage'
				);
			}
			$out .= $this->_li(
				'dps', '(' .$attr['dps'] . ' [[DPS|damage per second]])'
			);
			if ($a('feraldps', $attr)) { 
				$out .= ' <li class="feralAP" title="Only applies for druids">(' .
					$attr['feraldps'] . ' [[Feral attack power|<span class="cc-' .
					'druid">feral attack power</span>]])' . "\n";
			}
		}
		if ($a('armor', $attr)) { // basic armor
			$out .= $this->_li('armor', $attr['armor'] . ' [[Armor]]');
		}
		if ($a('block', $attr)) { // block value on shields only
			$out .= $this->_li(
				'block', $attr['block'] . ' [[Block value|Block]]'
			);
		}

		foreach ($this->attributes as $at) { // str/agi/stam/int/spr
			if ($a(strtolower($at), $attr)) {
				$out .= $this->_li(
					"attrib attr$at", '+' .
					$attr[strtolower($at)] . " [[$at]]"
				);
			}
		}
		if ($a('resist', $attr)) { //fire/nature/frost/shadow/arcane resist
			foreach ($attr['resist'] as $sch=>$resist) {
				$school = $this->schools[$sch];
				$out .= $this->_li(
					"resist res$school", "+$resist [[$school ".
					"resistance|$school Resistance]]"
				);
			}
		}
		if ($a('socketed', $attr)) {
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

		if ($a('durability', $attr)) { 
			$out .= $this->_li(
				'durability', '[[Durability]] ' . $attr['durability'] .
				' / ' . $attr['durability']
			);
		}
		if ($a('locked', $attr)) { //these 2 cases mutually exclusive
			$out .= $this->_li('locked', '[[Locked]]');
		} elseif ($a('lockpick', $attr)) {
			$out .= $this->_li(
				'req reqLockpick', 'Requires [[Lockpicking]] (' .
				$attr['lockpick'] . ')'
			);
		}
		if ($a('bagtype', $attr)) { //these 2 cases mutually exclusive
			$out .= $this->_li(
				'bag', $attr['bagslots'] . ' Slot [[' . $attr['bagtype'] . ']]'
			);
		} elseif ($a('bagslots', $attr)) { //just a regular bag
			$out .= $this->_li('bag', $attr['bagslots'] . ' Slot [[Bag]]');
		}
		if ($a('class', $attr)) { // class
			$class = '';
			foreach ($attr['class'] as $k=>$v) {
				$class .= $this->class[$k] . ', ';
			}
			$out .= $this->_li(
				'req reqClass', '[[Class|Classes]]: ' . substr($class, 0, -2)
			);
		}
		if ($a('race', $attr)) { // race
			$race = '';
			foreach ($attr['race'] as $k=>$v) {
				$race .= $this->races[$k] . ', ';
			}
			$out .= $this->_li(
				'req reqRace', '[[Race|Races]]: ' . substr($race, 0, -2)
			);
		}
		if ($a('level', $attr)) { // level
			$out .= $this->_li('req Level', 'Requires Level ' . $attr['level']);
		}
		if ($a('ilvl', $attr)) { // item level (REQUIRED)
			$out .= $this->_li(
				'iLvl', '[[Item level|Item Level]] ' . $attr['ilvl']
			);
		}
		if ($a('subskill', $attr)) { // skill specialization
			$out .= $this->_li(
				'req reqSubskill', 'Requires [[' . $attr['subskill'] . ']]'
			);
		}
		if ($a('profrequired', $attr)) { // skill rating
			$out .= $this->_li(
				'req reqSkill', 'Requires [[' . $attr['skill'] . ']] (' .
				$attr['skillrating'] . ')'
			);
		}
		if ($a('reprequired', $attr)) { // reputation FT
			$out .= $this->_li(
				'req reqRep', 'Requires [[' . $attr['faction'] . ']] – [[' .
				$this->replevels[$attr['factionrating']] . ']]'
			);
		}
		if ($a('arena', $attr)) {
			$out .= $this->_li(
				'req reqArena', 'Requires [[Arena personal rating|' .
				'personal]] and [[Arena team rating|team]] arena rating of ' .
				$attr['arena']
			);
		}
		if ($a('onhit', $attr)) { // bonus chance on hit
			foreach ($attr['onhit'] as $onhit) {
				$out .= $this->_li('bonus onhit', "Chance on hit: $onhit");
			}
		}

		//hard-coded bonuses
		if ($a('defense', $attr)) {
			$out .= $this->_li(
				'bonus defense', 'Equip: Increases [[defense rating]] by ' .
				$attr['defense'] . '.'
			);
		}
		if ($a('dodge', $attr)) {
			$out .= $this->_li(
				'bonus dodge', 'Equip: Increases your [[dodge rating]] by ' .
				$attr['dodge'] . '.'
			);
		}
		if ($a('parry', $attr)) {
			$out .= $this->_li(
				'bonus parry', 'Equip: Increases your [[parry rating]] by ' .
				$attr['parry'] . '.'
			);
		}
		if ($a('blockrating', $attr)) {
			$out .= $this->_li(
				'bonus block', 'Equip: Increases your shield [[block rating]] by ' .
				$attr['blockrating'] . '.'
			);
		}
		if ($a('haste', $attr)) {
			$out .= $this->_li(
				'bonus haste', 'Equip: Improves [[haste rating]] by ' .
				$attr['haste'] . '.'
			);
		}
		if ($a('hit', $attr)) {
			$out .= $this->_li(
				'bonus hit', 'Equip: Improves [[hit rating]] by ' .
				$attr['hit'] . '.'
			);
		}
		if ($a('crit', $attr)) {
			$out .= $this->_li(
				'bonus crit', 'Equip: Improves [[critical strike rating]] by ' .
				$attr['crit'] . '.'
			);
		}
		if ($a('resilience', $attr)) {
			$out .= $this->_li(
				'bonus resil', 'Equip: Improves [[resilience rating]] by ' .
				$attr['resilience'] . '.'
			);
		}
		if ($a('expertise', $attr)) {
			$out .= $this->_li(
				'bonus exp', 'Equip: Increases your [[expertise rating]] by ' .
				$attr['expertise'] . '.'
			);
		}
		if ($a('ap', $attr)) {
			$out .= $this->_li(
				'bonus ap', 'Equip: Increases [[attack power]] by ' .
				$attr['ap'] . '.'
			);
		}
		if ($a('mp5', $attr)) {
			$out .= $this->_li(
				'bonus mp5', 'Equip: Restores ' . $attr['mp5'] .
				' [[MP5|mana per 5]] sec.'
			);
		}
		if ($a('arp', $attr)) {
			$out .= $this->_li(
				'bonus arp', 'Equip: Increases your [[armor penetration ' .
				'rating]] by ' . $attr['arp'] . '.'
			);
		}
		if ($a('spellpower', $attr)) {
			$out .= $this->_li(
				'bonus sp', 'Equip: Increases [[spell power]] by ' .
				$attr['spellpower'] . '.'
			);
		}

		if ($a('equip', $attr)) { // other bonus equip effects FT
			foreach ($attr['equip'] as $equip) {
				$out .= $this->_li('bonus equip', "Equip: $equip");
			}
		}
		if ($a('use', $attr)) { // other bonus use effects FT
			foreach ($attr['use'] as $use) {
				$out .= $this->_li('bonus use', "Use: $use");
			}
		}
		if ($a('recipe', $attr)) { // recipe that creates another item
			$out .= $this->_li(
				'create', '{{Loot|' . $this->qualities[$attr['createq']] .
				'|' . $attr['create'] . '}}'
			);
			$out .= $this->_li('reagents', 'Requires ' . $attr['reagents']);
		}
		if ($a('charges', $attr) && is_numeric($attr['charges'])) {
			$out .= $this->_li('charges', $attr['charges'] . ' [[Charges]]');
		}
		if ($a('flavor', $attr)) { // FT
			$out .= $this->_li('flavor', '"' . $attr['flavor'] . '"');
		}
		if ($a('read', $attr)) { 
			$out .= $this->_li('bonus read', '<Right Click to Read>');
		}
		if ($a('open', $attr)) { 
			$out .= $this->_li('bonus open', '<Right Click to Open>');
		}
		if ($a('setpiece', $attr)) {
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
		if ($a('sell', $attr)) { // FT
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
		$a = 'array_key_exists';
		$out = '{{#set:Name=' . $attr['name'] . '|Item page=' .
			$attr['itempage'] . '|Quality=' .
			$this->qualities[$attr['quality']] . '|Icon=' . $attr['icon'] .
			'|ID=' . $attr['id'];

		if ($a('heroic', $attr)) {
			$out .= '|Heroic=true';
		}
		if ($a('conjured', $attr)) {
			$out .= '|Conjured=true';
		}
		if ($a('holiday', $attr)) {
			$out .= '|Requires holiday=' . $attr['holiday'];
		}
		if ($a('locationbind', $attr)) {
			$out .= '|Requires zone=' . $attr['locationbind'];
		}
		if ($a('bind', $attr)) {
			$bind = explode('|', $attr['bind']);
			$out .= '|Bind type=' .substr($bind[0], 2);
		}
		if ($a('questitem', $attr)) {
			$out .= '|Quest item=true';
		}
		if ($a('unique', $attr)) {
			$out .= '|Unique=true';
		} elseif ($a('uniqueN', $attr)) {
			$out .= '|Max quantity=' . $attr['uniqueN'];
		} elseif ($a('uniqueEq', $attr)) {
			$out .= '|Unique-equipped=true';
		} elseif ($a('uniqueEqN', $attr)) {
			$out .= '|Max equipped=' . $attr['uniqueEqN'];
		}
		if ($a('glyph', $attr)) {
			$glyph = explode('|', $attr['glyph']);
			$out .= '|Glyph type=' . substr($glyph[0], 2);
		}
		if ($a('duration', $attr)) {
			$out .= '|Limited duration=' . $attr['duration'];
		}
		if ($a('qbegin', $attr)) {
			$out .= '|Begins a quest=true|Quest begin=' . $attr['qbegin'];
		}
		if ($a('type', $attr)) {
			$type = explode('|', $attr['type']);
			$type[1] = ($a(1, $type) ? substr($type[1], 0, -2) : false);
			$out .= '|Equipment type=' . substr($type[0], 2);
		}
		if ($a('slot', $attr)) {
			$out .= '|Slot=' . $this->slots[$attr['slot']];
		}
		if ($a('weapon', $attr)) {
			$out .= '|Speed=' . $attr['speed'] . '|Low damage=' .
				$attr['dmg'][0] . '|High damage=' . $attr['dmg'][1] .
				'|Damage per second=' . $attr['dps'];
			if ($a('damageschool', $attr)) {
				$out .= '|Damage school=' .
					$this->schools[$attr['damageschool']];
			}
			if ($a('bonusdamage', $attr)) {
				$out .= '|Bonus low damage=' .$attr['bdmg'][0] .
					'|Bonus high damage=' . $attr['bdmg'][1];
				if ($a('bonusdamageschool', $attr)) {
					$out .= '|Bonus damage school=' .
						$this->schools[$attr['bonusdamageschool']];
				}
			}
		}
		if ($a('armor', $attr)) {
			$out .= '|Armor=' . $attr['armor'];
		}
		if ($a('block', $attr)) {
			$out .= '|Block=' . $attr['block'];
		}
		foreach ($this->attributes as $attrib) {
			if ($a(strtolower($attrib), $attr)) {
				$out .= "|$attrib=" . $attr[strtolower($attrib)];
			}
		}
		if ($a('resist', $attr)) {
			foreach ($attr['resist'] as $sch=>$resist) {
				$school = $this->schools[$sch];
				$out .= "|$school resistance=" . $resist;
			}
		}
		if ($a('socketed', $attr)) {
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
		if ($a('durability', $attr)) {
			$out .= '|Durability=' . $attr['durability'];
		}
		if ($a('locked', $attr)) {
			$out .= '|Locked=true';
		} elseif ($a('lockpick', $attr)) {
			$out .= '|Requires lockpicking=' . $attr['lockpick'];
		}
		if ($a('bagtype', $attr)) {
			$out .= '|Specialty bag=' . $attr['bagtype'];
		}
		if ($a('bagslots', $attr)) {
			$out .= '|Bag slots=' . $attr['bagslots'];
		}
		if ($a('class', $attr)) {
			foreach ($attr['class'] as $k=>$v) {
				$class = $this->class[$k];
				$out .= "|Requires class=$class";
			}
		}
		if ($a('race', $attr)) {
			foreach ($attr['race'] as $k=>$v) {
				$race = $this->races[$k];
				$out .= "|Requires race=$race";
			}
		}
		if ($a('level', $attr)) {
			$out .= '|Requires level=' . $attr['level'];
		}
		if ($a('ilvl', $attr)) {
			$out .= '|Item level=' . $attr['ilvl'];
		}
		if ($a('subskill', $attr)) {
			$out .= '|Requires specialization=' . $attr['subskill'];
		}
		if ($a('profrequired', $attr)) {
			$out .= '|Requires profession=' . $attr['skill'] .
				'|Requires profession skill=' . $attr['skillrating'];
		}
		if ($a('reprequired', $attr)) {
			$out .= '|Requires reputation=' . $attr['faction'] .
				'|Requires reputation rating=' .
				$this->replevels[$attr['factionrating']];
		}
		if ($a('arena', $attr)) {
			$out .= '|Requires arena rating=' . $attr['arena'];
		}
		if ($a('onhit', $attr)) { //there should only be 1
			foreach ($attr['onhit'] as $onhit) {
				$out .= "|Chance on hit=$onhit";
			}
		}
		//hard-coded bonuses
		if ($a('defense', $attr)) {
			$out .= '|Defense rating=' . $attr['defense'];
		}
		if ($a('dodge', $attr)) {
			$out .= '|Dodge rating=' . $attr['dodge'];
		}
		if ($a('parry', $attr)) {
			$out .= '|Parry rating=' . $attr['parry'];
		}
		if ($a('blockrating', $attr)) {
			$out .= '|Block rating=' . $attr['blockrating'];
		}
		if ($a('haste', $attr)) {
			$out .= '|Haste rating=' . $attr['haste'];
		}
		if ($a('hit', $attr)) {
			$out .= '|Hit rating=' . $attr['hit'];
		}
		if ($a('crit', $attr)) {
			$out .= '|Critical strike rating=' . $attr['crit'];
		}
		if ($a('resilience', $attr)) {
			$out .= '|Resilience rating=' . $attr['resilience'];
		}
		if ($a('expertise', $attr)) {
			$out .= '|Expertise rating=' . $attr['expertise'];
		}
		if ($a('ap', $attr)) {
			$out .= '|Attack power=' . $attr['ap'];
		}
		if ($a('mp5', $attr)) {
			$out .= '|Mana regeneration=' . $attr['mp5'];
		}
		if ($a('arp', $attr)) {
			$out .= '|Armor penetration rating=' . $attr['arp'];
		}
		if ($a('spellpower', $attr)) {
			$out .= '|Spell power=' . $attr['spellpower'];
		}
		if ($a('equip', $attr)) { //other equip lines
			foreach ($attr['equip'] as $equip) {
				$out .= "|Equip=$equip";
			}
		}
		if ($a('use', $attr)) { //there should only be 1
			foreach ($attr['use'] as $use) {
				$out .= "|Use=$use";
			}
		}
		if ($a('recipe', $attr)) {
			$out .= '|Is recipe=true|Creates=' . $attr['create'] .
			'|Create quality=' . $this->qualities[$attr['createq']] .
			'|Reagents=' . $attr['reagents'];
		}
		if ($a('charges', $attr)) {
			$out .= '|Charges=' . $attr['charges'];
		}
		if ($a('flavor', $attr)) {
			$out .= '|Flavor=' . $attr['flavor'];
		}
		if ($a('read', $attr)) {
			$out .= '|Readable=true';
		}
		if ($a('open', $attr)) {
			$out .= '|Openable=true';
		}
		if ($a('setpiece', $attr)) {
			$out .= '|In set' . $attr['set'] . '|Set page=' .
				$attr['setpage'] . '|Set pieces=' . $attr['setpieces'];
		}
		if ($a('sell', $attr)) {
			'|Sell price=' . $attr['sell'];
		}
		return "$out}} [[Category:Item pages|" . $attr['itempage'] . "]]";
	}

}
