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
 * WoWItem parser class
 *
 * @package MediaWiki
 * @author  James Twyford <jtwyford@gmail.com>
 * @license http://www.gnu.org/copyleft/gpl.html GNU General Public License 2.0 or later
 * @link    http://www.wowwiki.com WoWWiki
 */
class WoWItemParser
{

	/**
	 * parse $input and $args and return an array defining the item
	 *
	 * @param string $input  content between the <item>foo</item> tags
	 * @param array  $args   parameters to the <item foo=bar> opening tag
	 * @param Parser $parser the MWparser used to parse this page
	 *
	 * @return array a parsed string ready to display
	 */
	public function parse($input, $args, $parser) 
	{
		//first, parse the $input
		$attr = $this->_parseInput($input);
		if (!is_array($attr)) {
			return $attr; //error message
		}
		/*
		 * I'm passing the array into the parseArgs method so $args will
		 * override $input. $input is expected to be pretty much a
		 * copy-and-paste of a tooltip from the armory or an itemdb. $args is
		 * keyed parameters in the form of name="Corrupted Ashbringer", so
		 * it's rather explicit what's going on.
		 */
		$err = $this->_parseArgs($attr, $args);
		if (is_string($err)) {
			return $err;
		}
		//sanity check -- $attr can get modified, so watch out
		$err = $this->_sanityCheck($attr);
		if (is_string($err)) {
			return $err;
		}
		//ok, its fully-defined and ready to go
		return $attr;
	}
	/**
	 * parse input between <item>foo bar</item> tags
	 * input is expected to be a tooltip copy/pasted from elsewhere
	 *
	 * @param string $input the input between the item tags
	 *
	 * @return array formatted input in an array ready for reuse
	 */
	private function _parseInput($input) 
	{
		$attr = array();

		// $input is a string. I want an array.
		$temp = explode("\n", htmlspecialchars($input));

		// empty case, hopefully we've got params
		if (count($temp) == 1) {
			return $attr;
		}

		// I'm also inverting the order because...
		$in = array_reverse($temp);
		unset ($temp);

		// faster to pop off the stack than it is to shift off the queue
		$attr['name'] = array_pop($in); // name must be the first line, but...
		if ($attr['name'] == '') { // may not be on the same line as the tag
			$attr['name'] = array_pop($in);
		}

		// time to loop
		$val = array_pop($in);
		while ($val !== null) {
			// start searching the string for a keyword
			if (strpos($val, 'Heroic') !== false) {
				$attr['heroic'] = true;
			} elseif (strpos($val, 'Conjured') !== false) {
				$attr['conjured'] = true;
			} elseif (strpos($val, 'Binds') !== false) {
				$bind = explode(' ', $val);
				$attr['bind'] = $this->_binds($bind[2]);
				if (!$attr['bind']) {
					$attr = $this->_error(
						'bind', 'one of the four bind types',
						$attr['bind'], 'Bind'
					);
				}
				unset ($bind);
			} elseif (strpos($val, 'Quest Item') !== false) {
				$attr['questitem'] = true;
			} elseif (strpos($val, 'Unique') !== false) {
				$uniq = explode(' ', $val);
				if ($uniq[0] == 'Unique') {
					if (count($uniq) == 1) {
						$attr['unique'] = true;
					} else {
						$attr['uniqueN'] = substr($uniq[1], 1, -1);
					}
				} elseif ($uniq[0] == 'Unique-Equipped') {
					if (count($uniq) == 1) {
						$attr['uniqueEq'] = true;
					} else {
						$attr['uniqueEqN'] = substr($uniq[1], 1, -1);
					}
				}
				unset ($uniq);
			} elseif (strpos($val, 'Glyph') !== false) {
				$glyph = substr($val, 0, 5);
				if ($glyph == 'Major') {
					$attr['glyph'] = '[[Major glyph|Major Glyph]]';
				} elseif ($glyph == 'Minor') {
					$attr['glyph'] = '[[Minor glyph|Minor Glyph]]';
				} else {
					return $this->_parseError(
						'Glyphs are either "Major Glyph" or "Minor Glyph"',
						'Major Glyph'
					);
				}
			} elseif (strpos($val, 'Duration') !== false) { //FT
				$attr['duration'] = trim(substr($val, 10));
			} elseif (strpos($val, 'Begins a Quest') !== false) {
				if (strlen(trim($val)) < 26) {
					return $this->_parseError(
						'Please add the started quest to the "This Item ' .
						'Begins a Quest" line',
						'This Item Begins a Quest Your Place in the World'
					);
				}
				$attr['qbegin'] = trim(substr($val, 25));
			} elseif (strpos($val, 'Speed') !== false) {
				$attr['speed'] = trim(substr($val, 5));
			} elseif (strpos($val, 'Damage') !== false) {
				$dmg = explode(' ', $val);
				$mid = strpos($dmg[0], '-');
				$bonus = ($dmg[0][0] == '+'); // low-end starts with a +
				if ($mid === false) { // "11 - 44 [Frost] Damage"
					if ($bonus) {
						$attr['bdmg'][0] = substr($dmg[0], 1);
						$attr['bdmg'][1] = $dmg[2];
					} else {
						$attr['dmg'][0] = $dmg[0];
						$attr['dmg'][1] = $dmg[2];
					}
					$school = $this->_school($dmg[3]); // 'Frost' or 'Damage'
					if ($school !== false) {
						$attr[
							(($dmg[0][0] == '+') ? 'bonus' : '') .
							'damageschool'
						] = $school;
					}
				} else { // "11-44 [Frost] Damage"
					if ($bonus) {
						$attr['bdmg'][0] = substr($dmg[0], 1, $mid-1);
						$attr['bdmg'][1] = substr($dmg[0], $mid+1);
					} else {
						$attr['dmg'][0] = substr($dmg[0], 0, $mid);
						$attr['dmg'][1] = substr($dmg[0], $mid+1);
					}
					$school = $this->_school($dmg[1]); // 'Frost' or 'Damage'
					if ($school !== false) {
						$attr[
							(($dmg[0][0] == '+') ? 'bonus' : '') .
							'damageschool'
						] = $school;
					}
				}
				unset ($dmg) ;
			} elseif (strpos($val, 'damage per second') !== false) {
				$dps = explode(' ', $val);
				if (is_numeric(substr($dps[0], 1))) {
					$attr['dps'] = substr($dps[0], 1);
				}
				unset ($dps);
			} elseif (strpos($val, 'feral attack power') !== false) {
				// don't care. we will calculate it later
			} elseif (strpos($val, 'Socket') !== false) {
				// before the various stat checks so we catch sockbonuses
				if (substr($val, 0, 6) == 'Socket') {
					$attr['sockbonus'] = substr($val, 14);
				} else {
					$temp = $this->_socket($val[0]);
					if ($temp !== false) {
						$attr['socket'][] = $temp;
					}
				}
			} elseif (strpos($val, 'Armor') !== false) {
				$temp = trim(substr($val, 0, -6));
				if (is_numeric($temp)) {
					$attr['armor'] = $temp;
				}
			} elseif (strpos($val, 'Block') !== false) {
				$temp = trim(substr($val, 0, -5));
				if (is_numeric($temp)) {
					$attr['block'] = $temp;
				}
			} elseif (strpos($val, 'Strength') !== false) {
				$temp = trim(substr($val, 1, -9));
				if (is_numeric($temp)) {
					$attr['strength'] = $temp;
				}
			} elseif (strpos($val, 'Agility') !== false) {
				$temp = trim(substr($val, 1, -8));
				if (is_numeric($temp)) {
					$attr['agility'] = $temp;
				}
			} elseif (strpos($val, 'Stamina') !== false) {
				$temp = trim(substr($val, 1, -8));
				if (is_numeric($temp)) {
					$attr['stamina'] = $temp;
				}
			} elseif (strpos($val, 'Intellect') !== false) {
				$temp = trim(substr($val, 1, -10));
				if (is_numeric($temp)) {
					$attr['intellect'] = $temp;
				}
			} elseif (strpos($val, 'Spirit') !== false) {
				$temp = trim(substr($val, 1, -7));
				if (is_numeric($temp)) {
					$attr['spirit'] = $temp;
				}
			} elseif (strpos($val, 'Resistance') !== false) {
				$res = explode(' ', $val);
				$school = $this->_school($res[1]);
				if ($school !== false) {
					$attr['resist'][$school] = substr($res[0], 1);
				}
			} elseif (strpos($val, 'Durability') !== false) {
				$dura = explode(' ', $val);
				if (is_numeric($dura[1])) { //'dura 300 / 300'
					$attr['durability'] = $dura[1];
				} else {
					$dur = substr(strstr($dura[1], '/'), 1);
					if (is_numeric($dur)) { //'dura 300/300'
						$attr['durability'] = $dur;
					}
				}
				unset ($dura);
			} elseif (strpos($val, 'defense rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['defense'] = $match[0];
			} elseif (strpos($val, 'dodge rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['dodge'] = $match[0];
			} elseif (strpos($val, 'parry rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['parry'] = $match[0];
			} elseif (strpos($val, 'shield block rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['blockrating'] = $match[0];
			} elseif (strpos($val, 'haste rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['haste'] = $match[0];
			} elseif (strpos($val, 'hit rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['hit'] = $match[0];
			} elseif (strpos($val, 'critical strike rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['crit'] = $match[0];
			} elseif (strpos($val, 'resilience rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['resilience'] = $match[0];
			} elseif (strpos($val, 'expertise rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['expertise'] = $match[0];
			} elseif (strpos($val, 'attack power by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['ap'] = $match[0];
			} elseif (strpos($val, 'mana per 5 sec.') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['mp5'] = $match[0];
			} elseif (strpos($val, 'armor penetration rating by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['arp'] = $match[0];
			} elseif (strpos($val, 'spell power by') !== false) {
				preg_match('/(\d+)/', $val, $match);
				$attr['spellpower'] = $match[0];
			} elseif (strpos($val, 'Locked') !== false) {
				$attr['locked'] = true;
			} elseif (strpos($val, 'Lockpicking') !== false) {
				$temp = substr(strstr($val, '('), 1, -1);
				if (is_numeric($temp)) {
					$attr['lockpick'] = $temp;
				}
			} elseif (strpos($val, 'Bag') !== false) {
				$bag = explode(' ', $val);
				if (is_numeric($bag[0])) {
					$attr['bagslots'] = $bag[0];
					$type = $this->_bagType($bag[2]);
					if ($type !== false) {
						$attr['bagtype'] = $type;
					}
				}
				unset ($bag);
			} elseif (strpos($val, 'Classes') !== false) {
				$classes = explode(' ', $val);
				unset ($classes[0]);
				foreach ($classes as $c) {
					$class = $this->_classes($c);
					if ($class === false) {
						return $this->_error(
							'class', 'one of the playable classes', $val
						);
					} else {
						$attr['class'][$class] = true;
					}
				}
			} elseif (strpos($val, 'Races') !== false) {
				$races = explode(' ', $val);
				unset ($races[0]);
				foreach ($races as $r) {
					if ($r == 'Elf,') {
						continue;
					}
					$race = $this->_races($r);
					if ($race === false) {
						return $this->_error('race', 'a playable race', $r);
					} else {
						$attr['race'][$race] = true;
					}
				}
			} elseif (strpos($val, 'Requires Level') !== false) {
				$temp = trim(substr($val, 15));
				if (is_numeric($temp)) {
					$attr['level'] = $temp;
				}
			} elseif (strpos($val, 'Item Level') !== false) {
				$temp = trim(substr($val, 11));
				if (is_numeric($temp)) {
					$attr['ilvl'] = $temp;
				}
			} elseif (strpos($val, 'Charges') !== false) {
				$chg = explode(' ', $val);
				if (is_numeric($chg[0])) {
					$attr['charges'] = $chg[0];
				}
				unset ($chg);
			} elseif (strpos($val, 'Right Click to Read') !== false) {
				$attr['read'] = true;
			} elseif (strpos($val, 'Right Click to Open') !== false) {
				$attr['open'] = true;
			} elseif (strpos($val, 'Sell Price') !== false) {
				$sell = explode(' ', $val);
				foreach ($sell as $v) {
					if (in_array(substr($v, -1), array('g','s','c'))) {
						$k = substr($v, -1);
						$$k = substr($v, 0, -1);
					}
				}
				$attr['sell'] = '{{cost|' .
					(isset ($g) ? "$g" : '') .
					(isset ($s) ? "|$s" : '|') .
					(isset ($c) ? "|$c" : '') . '}}';
				unset ($sell);
			} elseif (strpos($val, 'arena') !== false) {
				$temp = trim(substr($val, 43));
				if (is_numeric($temp)) {
					$attr['arena'] = $temp;
				}
			} elseif ((strpos($val, '/') !== false) //x/y
				|| (stripos($val, 'pieces') !== false) //x pieces
			) { //set
				$paren = strpos($val, '(');
				$attr['set'] = trim(substr($val, 0, $paren-1));
				$attr['setpieces'] = substr($val, $paren+1, 1); // (x pieces)
				if (!is_numeric($attr['setpieces'])) {
					return $this->_parseError(
						'Please define the size of the equipment set on the ' .
						'same line', 'Netherwind Regalia (0/8)'
					);
				} elseif (
					($attr['setpieces'] == 0) || ($attr['setpieces'] == 1)
				) { //(x/y). x isn't really all that useful
					$pos = strpos($val, '/');
					$attr['setpieces'] = substr($val, $pos+1, 1);
				}
				for ($i = 0; $i < $attr['setpieces']; $i++) {
					array_pop($in) ."\n"; //pop set items. not our problem
				}
				$val = array_pop($in);
				while ($val[0] == '(') {
					$val = array_pop($in); //pop set bonuses. not our problem
				}
				array_push($in, $val); //first non set-bonus back on the stack
			} elseif (substr($val, 0, 1) == '[') { //it's a recipe!
				$end = strpos($val, ']');
				$attr['create'] = substr($val, 1, $end-1);
				$recipe = explode(' ', $val);
				$qual = array_pop($recipe);
				unset ($recipe);
				if ($this->_quality($qual) !== false) {
					$attr['createq'] = $this->_quality($qual);
				} else {
					return $this->_parseError(
						'Please add the created item\'s quality to the end ' .
						'of the line where it is listed.',
						'[Heavy Runecloth Bandage] common'
					);
				}
			} elseif (substr($val, 0, 6) == '&quot;') { //favor!
				$attr['flavor'] = substr($val, 6, -6);
			} elseif (substr($val, 0, 14) == 'Chance on hit:') {
				$attr['onhit'][] = trim(substr($val, 15));
			} elseif (substr($val, 0, 6) == 'Equip:') {
				$attr['equip'][] = trim(substr($val, 7));
			} elseif (substr($val, 0, 4) == 'Use:') {
				$attr['use'][] = trim(substr($val, 5));
			} elseif (strpos($val, 'Requires') !== false) {
				//ok... now it gets expensive. yuck.
				//holiday, subskill, skill, faction, reagents
				$requires = explode(' ', $val);
				unset ($requires[0]);
				if ($this->_holiday($requires[1]) !== false) {
					$attr['holiday'] = $this->_holiday($requires[1]);
				} elseif ($this->_subskill($requires[1]) !== false) {
					$attr['subskill'] = $this->_subskill($requires[1]);
				} elseif ($this->_profession($requires[1]) !== false) {
					$attr['skill'] = $this->_profession($requires[1]);
					preg_match('/(\d+)/', array_pop($requires), $match);
					if (count($match) == 2) { //preg_match adds it twice?
						$attr['skillrating'] = $match[0];
					} else {
						return $this->_parseError(
							'Please add the numeric reputation requirement ' .
							'to the end of the line where it is listed.',
							'Requires ' . $attr['skill'] .' (400)'
						);
					}
				} else {
					$rep = array_pop($requires);
					if ($this->_repRating($rep) !== false) {
						array_pop($requires); //getting rid of the '-'
						$attr['faction'] = implode($requires, ' ');
						$attr['factionrating'] = $this->_repRating($rep);
					} else { //reagents
						array_push($requires, $rep);
						$attr['reagents'] = implode($requires, ' ');
					}
				}
			} elseif ($this->_types($val) !== false) {
				$attr['type'] = $this->_types($val);
			} elseif ($this->_slots($val) !== false) {
				$attr['slot'] = $this->_slots($val);
			} else { //out of ideas at this point.
				$attr['undef'][] = $val;
			}
			$val = array_pop($in);
		}
		unset ($in);
		return $attr;
	}

	/**
	 * parse arguments to the <item foo=bar/> tag
	 *
	 * @param array &$attr the array we're parsing the input in to
	 * @param array $args  the input passed in the item tag
	 *
	 * @return string any error output
	 */
	private function _parseArgs(&$attr, $args) 
	{

		//kd3 is lazy. $a is quicker to type than array_key_exists.
		$h = 'htmlspecialchars'; //always, always, ALWAYS escape user input

		// parse!
		foreach ($args as $k => $v) {
			switch (strtolower($h($k))) {
			// one-letter keys used: abcd_ ghi _l_n_ qrstu_ _ z
			case 'a': case 'armor':
				$attr['armor'] = $h($v);
				if (!is_numeric($attr['armor'])) {
					return $this->_error('armor', 'numeric', $attr['armor']);
				}
				break;
			case 'b': case 'bind': case 'binds':
				$attr['bind'] = $this->_binds($h($v));
				if (!$attr['bind']) {
					return $this->_error(
						'bind', 'one of the four bind types', $attr['bind']
					);
				}
				break;
			case 'c': case 'cl': case 'class': case 'classes':
				$classes = explode(' ', $h($v));
				foreach ($classes as $c) {
					$class = $this->_classes($c);
					if ($class === false) {
						return $this->_error(
							'class', 'one of the playable classes', $h($v)
						);
					} else {
						$attr['class'][] = $class;
					}
				}
				break;
			case 'd': case 'dur': case 'dura': case 'durability':
				$attr['durability'] = $h($v);
				if (!is_numeric($attr['durability'])) {
					return $this->_error(
						'durability', 'numeric', $attr['durability']
					);
				}
				break;
			case 'g': case 'glyph':
				if (stripos($h($v), 'mi') !== false) {
					$attr['glyph'] = '[[Minor glyph|Minor Glyph]]';
				} else {
					$attr['glyph'] = '[[Major glyph|Major Glyph]]';
				}
				break;
			case 'h': case 'heroic': //bool
				$attr['heroic'] = true;
				break;
			case 'i': case 'icon': //mandatory
				$attr['icon'] = ucfirst(strtolower($h($v)));
				break;
			case 'l': case 'level':
				$attr['level'] = $h($v);
				if (!is_numeric($attr['level'])) {
					return $this->_error('level', 'numeric', $attr['level']);
				}
				break;
			case 'n': case 'name': //FT mandatory
				$attr['name'] = ucfirst($h($v));
				break;
			case 'q': case 'qual': case 'quality':
				$attr['quality'] = $this->_quality($h($v));
				if (!$attr['quality']) {
					return $this->_error(
						'quality', 'a valid item quality', $attr['quality']
					);
				}
				break;
			case 'r': case 'race': case 'races':
				$races = explode(' ', $h($v));
				foreach ($races as $r) {
					$race = $this->_races($r);
					if ($race === false) {
						return $this->_error(
							'race', 'one of the playable races', $h($v)
						);
					} else {
						$attr['race'][$race] = true;
					}
				}
				break;
			case 's': case 'slot':
				$attr['slot'] = $this->_slots($h($v));
				if (!$attr['slot']) {
					return $this->_error(
						'slot', 'a valid equipment slot', $attr['slot']
					);
				}
				break;
			case 't': case 'type':
				$attr['type'] = $this->_types($h($v));
				if (!$attr['type']) {
					return $this->_error(
						'type', 'a valid armor/weapon type', $attr['type']
					);
				}
				break;
			case 'u': case 'un': case 'unique': //unique-eq check is later
				$uniq = $h($v);
				if (is_numeric($uniq)) {
					$attr['uniqueN'] = $uniq; //"Unique (100)"
				} else {
					$attr['unique'] = true; //boolean -- "Unique (1)"
				}
				break;
			case 'z': case 'ins': case 'zone':
			case 'instance': case 'instancebind':
			case 'loc': case 'location': case 'locationbind'://FT
				$attr['locationbind'] = $h($v);
				break;

			//weapon stats
			case 'damage': case 'dmg':
				$dmg = explode(' ', $h($v));
				$mid = strpos($dmg[0], '-');
				if ($mid === false) { // '44 - 115 [Frost]'
					$attr['dmg'][0] = $dmg[0];
					$attr['dmg'][1] = $dmg[2];
				} else { // '44 - 115 [Frost]'
					$attr['dmg'][0] = substr($dmg[0], 0, $mid);
					$attr['dmg'][1] = substr($dmg[0], $mid+1);
				}
				break;
			case 'lowdamage': case 'damagelow': //explicitly-defined
				$attr['dmg'][0] = $h($v);
				break;
			case 'hidamage': case 'damagehigh': case 'highdamage':
				$attr['dmg'][1] = $h($v);
				break;
			case 'damageschool': case 'school':
				$attr['damageschool'] = $this->_school($h($v));
				if (!$attr['damageschool']) {
					return $this->_error(
						'damageschool', 'one of the WoW magic schools',
						$attr['damageschool']
					);
				}
				break;
			case 'speed':
				$attr['speed'] = $h($v);
				break;
			case 'bonus': case 'bdam':
				$dmg = explode(' ', $h($v));
				$mid = strpos($dmg[0], '-');
				if ($mid !== 'false') { // '44-115'
					$attr['bdmg'][0] = substr($dmg[0], 0, $mid);
					$attr['bdmg'][1] = substr($dmg[0], $mid+1);
				} else {
					// '44 - 115'
					$attr['bdmg'][0] = $dmg[0];
					$attr['bdmg'][1] = $dmg[2];
				}
				break;
			case 'bonuslowdamage': case 'bonusdamagelow':
				$attr['bdmg'][0] = $h($v);
				break;
			case 'bonushidamage': case 'bonusdamagehigh':
			case 'bonushighdamage':
				$attr['bdmg'][1] = $h($v);
				break;
			case 'bonusdamageschool': case 'bonusschool':
				$attr['bonusdamageschool'] = $this->_school($h($v));
				if (!$attr['bonusdamageschool']) {
					return $this->_error(
						'bonusdamageschool', 'one of the WoW magic schools',
						$attr['bonusdamageschool']
					);
				}
				break;
			case 'dps':
				$attr['dps'] = $h($v);
				break;

			// core stats
			case 'bl': case 'block':
				$attr['block'] = $h($v);
				break;
			case 'str': case 'strength':
				$attr['strength'] = $h($v);
				break;
			case 'agi': case 'agility':
				$attr['agility'] = $h($v);
				break;
			case 'sta': case 'stam': case 'stamina':
				$attr['stamina'] = $h($v);
				break;
			case 'int': case 'intellect':
				$attr['intellect'] = $h($v);
				break;
			case 'spi': case 'spr': case 'spirit':
				$attr['spirit'] = $h($v);
				break;
			//resists
			case 'fire':
				$attr['resist'][$this->_school('fire')] = $h($v);
				break;
			case 'frost':
				$attr['resist'][$this->_school('frost')] = $h($v);
				break;
			case 'nature':
				$attr['resist'][$this->_school('nature')] = $h($v);
				break;
			case 'shadow':
				$attr['resist'][$this->_school('shadow')] = $h($v);
				break;
			case 'arcane':
				$attr['resist'][$this->_school('arcane')] = $h($v);
				break;
			//bonuses
			case 'defense': case 'def':
				$attr['defense'] = $h($v);
				break;
			case 'dodge':
				$attr['dodge'] = $h($v);
				break;
			case 'parry':
				$attr['parry'] = $h($v);
				break;
			case 'resilience': case 'res': case 'resil':
				$attr['resilience'] = $h($v);
				break;
			case 'blockrating': case 'blockvalue':
				$attr['blockrating'] = $h($v);
				break;
			case 'hit':
				$attr['hit'] = $h($v);
				break;
			case 'crit':
				$attr['crit'] = $h($v);
				break;
			case 'haste':
				$attr['haste'] = $h($v);
				break;
			case 'expertise': case 'exp':
				$attr['expertise'] = $h($v);
				break;
			case 'attack': case 'ap': case 'attackpower':
				$attr['ap'] = $h($v);
				break;
			case 'armorpen': case 'arp':
				$attr['arp'] = $h($v);
				break;
			case 'mp5': case 'manaregen':
				$attr['mp5'] = $h($v);
				break;
			case 'sp': case 'spellpower': case 'spell':
				$attr['spellpower'] = $h($v);
				break;
			// others
			case 'arena':
				$attr['arena'] = $h($v);
				break;
			case 'bag': case 'bagslots':
				$attr['bagslots'] = $h($v);
				break;
			case 'bagtype':
				$attr['bagtype'] = $this->_bagType($h($v));
				if (!$attr['bagtype']) {
					return $this->_error(
						'bagtype', 'a valid bag type',	$attr['bagtype']
					);
				}
				break;
			case 'charge': case 'charges':
				$attr['charges'] = $h($v);
				break;
			case 'con': case 'conj': case 'conjured': //bool
				$attr['conjured'] = true;
				break;
			case 'create': //FT
				$attr['create'] = $h($v);
				break;
			case 'createq':
				$attr['createq'] = $this->_quality($h($v));
				if (!$attr['createq']) {
					return $this->_error(
						'createq', 'a valid item quality', $attr['createq']
					);
				}
				break;
			case 'disambig': case 'disambigpage': case 'itempage': //FT
				$attr['itempage'] = $h($v);
				break;
			case 'duration': //FT
				$attr['duration'] = $h($v);
				break;
			case 'equip': //FT
				$attr['equip'] = explode('/', $h($v));
				break;
			case 'faction': case 'rep': case 'reputation': //FT
				$attr['faction'] = ucwords(strtolower($h($v)));
				break;
			case 'factionrating': case 'reprating':
				$attr['factionrating'] = $this->_repRating($h($v));
				if (!$attr['factionrating']) {
					return $this->_error(
						'factionrating', 'a valid reputation level',
						$attr['factionrating']
					);
				}
				break;
			case 'flavor': //FT
				$attr['flavor'] = $h($v);
				break;
			case 'heroic': //bool
				$attr['heroic'] = true;
				break;
			case 'hol': case 'holiday': //FT
				$attr['holiday'] = ucwords(strtolower($h($v)));
				break;
			case 'id': //mandatory
				$attr['id'] = $h($v);
				break;
			case 'ilvl': //mandatory
				$attr['ilvl'] = $h($v);
				break;
			case 'locked': case 'lock': case 'lockpick': case 'lockpicking':
				$locked = $h($v);
				if (is_numeric($locked)) {
					$attr['lockpick'] = $h ($v);
				} else {
					$attr['locked'] = true;
				}
				break;
			case 'onhit': //chance on hit FT
				$attr['onhit'] = explode('/', $h($v));
				break;
			case 'open': // bool
				$attr['open'] = true;
				break;
			case 'qb': case 'qbegin': case 'questbegin': case 'beginquest':
				$attr['qbegin'] = $h ($v);
				break;
			case 'qi': case 'questitem': //bool
				$attr['questitem'] = true;
				break;
			case 'read': // bool
				$attr['read'] = true;
				break;
			case 'sell': // sell to vendor FT
				$attr['sell'] = $h($v);
				break;
			case 'reagents': //FT
				$attr['reagents'] = $h($v);
				break;
			case 'set': //FT
				$attr['set'] = $h($v);
				break;
			case 'setpieces': case 'setpc':
				$attr['setpieces'] = $h($v);
				break;
			case 'sock': case 'socket': case 'sockets':
				$sockets = explode(' ', $h($v));
				foreach ($sockets as $sock) {
					$attr['socket'][] = $this->_socket($sock);
				}
				break;
			case 'sockbonus': case 'socketbonus': //FT
				$attr['sockbonus'] = $h($v);
				break;
			case 'skill': case 'prof': case 'profession':
				$attr['skill'] = $this->_profession($h($v));
				if (!$attr['skill']) {
					return $this->_error(
						'skill', 'a playable profession', $attr['skill']
					);
				}
				break;
			case 'skillrating': case 'profrating': case 'professionrating':
				$attr['skillrating'] = $h($v);
				if (!is_numeric($attr['skillrating'])) {
					return $this->_error(
						'skillrating', 'numeric', $attr['skillrating']
					);
				}
				break;
			case 'sub': case 'spec': case 'specialty': case 'subskill':
			case 'specialization':
				$attr['subskill'] = $this->_subskill($h($v));
				if (!$attr['subskill']) {
					return $this->_error(
						'subskill', 'a valid profession specialization',
						$attr['subskill']
					);
				}
				break;
			case 'unique-equipped': case 'ueq': case 'unique-eq':
				$ueq = $h ($v);
				if ($ueq === $h ($k)) { //yes, strictly equal...
					$attr['uniqueEq'] = true; //Unique-Equipped
				} else {
					$attr['uniqueEqN'] = $ueq; //Unique-Equipped (something)
				}
				break;
			case 'use':
				$attr['use'] = explode('/', $h ($v));
				break;
			default:
				$attr['undef'][] = $h($k).": ".$h($v);
			}
		}
		//done with input parsing, yay! return true, we've modified the array
		return true;
	}

	/**
	 * do some sanity checking before giving the all-clear
	 *
	 * @param array &$attr the hopefully-sane item array
	 *
	 * @return string any error output
	 */
	private function _sanityCheck(&$attr)
	{
		//kd3 is lazy. $a is quicker to type than array_key_exists.
		$n = 'is_numeric';
		/*
		 * need some attributes before we go any further, can't make them up:
		 * name, id, ilvl
		 */
		if (!isset ($attr['name'], $attr['id'], $attr['ilvl'])
			|| !$n($attr['id']) || !$n($attr['ilvl'])
		) {
			return 'Tooltip error: <span class="error">All items must have ' .
				'name, ID and iLvl defined!</span>';
		}

		/*
		 * If we're being lazy not defining a disambig page (should be most of
		 * the time) define it here so we only have to check itempage and not
		 * "maybe check itempage or name" in the renderers.
		 */
		if (!isset ($attr['itempage'])) {
			$attr['itempage'] = $attr['name'];
		}

		//display an icon, even if the user doesn't define one. Bad user. Bad.
		if (!isset ($attr['icon'])) {
			$attr['icon'] = "Temp";
		}
		//quality poor if not defined. Bad user. Bad.
		if (!isset ($attr['quality'])) {
			$attr['quality'] = $this->_quality(0);
		}

		//weapon sanity check
		$weapon = (isset ($attr['dmg'], $attr['speed'], $attr['dps'])
				&& count($attr['dmg']) == 2 && $n($attr['dmg'][0])
				&& $n($attr['dmg'][1]) && $n($attr['dps'])
				&& $n($attr['speed']));
		if ((isset ($attr['dmg']) && !$weapon)
			|| (isset ($attr['speed']) && !$weapon)
			|| (isset ($attr['dps']) && !$weapon)
		) {
			return 'Tooltip error: <span class="error">Weapons must have ' .
			'low-end damage, high-end damage and attack speed defined!</span>';
		}
		if ($weapon) {
			$attr['weapon'] = true;

			//here. not the parsers. didn't know if 'type' was set then.
			$feral = in_array(
				$attr['type'], array('[[Staff]]', '[[Mace]]', '[[Polearm]]')
			);
			if ($feral && ($attr['dps'] > 54.8) ) {
				$attr['feraldps'] = (($attr['dps'] - 54.8) * 14);
			}

			$bonusdamage = (isset ($attr['bdmg']) && (count($attr['bdmg']) == 2)
				&& $n($attr['bdmg'][0]) && $n($attr['bdmg'][1]));
			if (!$weapon && !$bonusdamage && isset ($attr['bdmg'])) {
				return 'Tooltip error: <span class="error">Bonus low-end ' .
					'damage and bonus high-end damage must be defined ' .
					'together!</span>';
			}
			if ($bonusdamage) {
				$attr['bonusdamage'] = true;
			}
		}


		//recipe sanity check
		$recipe = isset ($attr['create'], $attr['createq'], $attr['reagents']);
		if ((isset ($attr['create']) && !$recipe)
			|| (isset ($attr['createq']) && !$recipe)
			|| (isset ($attr['reagents']) && !$recipe)
		) {
			return 'Tooltip error: <span class="error">Recipes must have ' .
				'"create", "createq" and "reagents" defined!</span>';
		}
		if ($recipe) {
			$attr['recipe'] = true;
		}

		//socket sanity check
		$socketed = isset ($attr['socket'], $attr['sockbonus']);
		if ((isset ($attr['socket']) && !$socketed)
			|| (isset ($attr['sockbonus']) && !$socketed)
		) {
			return 'Tooltip error: <span class="error">Socketed items must ' .
				'have a socket bonus!</span>';
		}
		if ($socketed) {
			$attr['socketed'] = true;
		}

		//rep requirement sanity check
		$rep = isset ($attr['faction'], $attr['factionrating']);
		if ((isset ($attr['faction']) && !$rep)
			|| (isset ($attr['factionrating']) && !$rep)
		) {
			return 'Tooltip error: <span class="error">Reputation ' .
				'requirements must list the required reputation ' .
				'rating!</span>';
		}
		if ($rep) {
			$attr['reprequired'] = true;
		}

		//profession requirement sanity check
		$prof = isset ($attr['skill'], $attr['skillrating']);
		if ((isset ($attr['skill']) && !$prof)
			|| (isset ($attr['skillrating']) && !$prof)
		) {
			return 'Tooltip error: <span class="error">Profession ' .
				'requirements must list the required profession ' .
				'skill!</span>';
		}
		if ($prof) {
			$attr['profrequired'] = true;
		}

		//bag sanity check
		if (isset ($attr['bagtype']) && !isset ($attr['bagslots'])) {
			return 'Tooltip error: <span class="error">A bagtype was ' .
			'specified, but not the size of the bag?</span>';
		}
		//set sanity check
		$set = isset ($attr['set'], $attr['setpieces']);
		if (isset ($attr['set']) && !isset ($attr['setpieces'])) {
			return 'Tooltip error: <span class="error">Please define the ' .
			'size of the item set with setpieces=x</span>';
		}
		if ($set) {
			$attr['setpiece'] = true;
			if (!isset ($attr['setpage'])) {
				$attr['setpage'] = $attr['set'];
			}
		}
		//"Requires Level 1" is met by default. Don't bother.
		if (isset ($attr['level']) && ($attr['level'] == 1)) {
			unset ($attr['level']);
		}
		//1, n, or infinite charges. #3 shouldn't be passed, ignore #1.
		if (isset ($attr['charges']) && ($attr['charges'] == 1)) {
			unset ($attr['charges']);
		}
		//unique (1) and Unique-Equipped (1) should lose the 1
		if (isset ($attr['uniqueN']) && ($attr['uniqueN'] == 1)) {
			$attr['unique'] = true;
			unset ($attr['uniqueN']);
		} elseif (isset ($attr['uniqueEqN']) && ($attr['uniqueEqN'] == 1)) {
			$attr['uniqueEq'] = true;
			unset ($attr['uniqueEqN']);
		}
		//if we only caught one unknown, it's hopefully our instance bind
		if (isset ($attr['undef']) && (sizeof($attr['undef']) == 1)) {
			$attr['locationbind'] = $attr['undef'][0];
		}

		//sort some of our sortable attributes
		if (isset ($attr['class'])) {
			$class = $attr['class'];
			ksort($class);
			$attr['class'] = $class;
		}
		if (isset ($attr['race'])) {
			$race = $attr['race'];
			ksort($race);
			$attr['race'] = $race;
		}
		if (isset ($attr['resist'])) {
			$resist = $attr['resist'];
			ksort($resist);
			$attr['resist'] = $resist;
		}

		//ok. the array should be sane, hopefully. return true -- no errors
		return true;
	}

	//now for a boatload of helper functions

	/**
	 * returns a schoolID
	 *
	 * @param string $in unformatted wow magic school
	 *
	 * @return string linked wow magic school
	 */
	private function _school($in)
	{
		if (is_numeric($in)) {
			return $in; //passed a schoolID by a script, return it
		}
		switch (strtolower($in)) {
		case 'arcane':
			return 7;
		case 'fire':
			return 3;
		case 'frost':
			return 5;
		case 'holy':
			return 2;
		case 'nature':
			return 4;
		case 'shadow':
			return 6;
		}
		return false;
	}

	/**
	 * returns one of the seven item qualities
	 *
	 * @param mixed $in quality
	 *
	 * @return int a qualityType (0-7)
	 */
	private function _quality($in)
	{
		if (is_numeric($in)) {
			return $in; // a script's passing in a qualityType, return it
		}
		switch (strtolower($in)) {
		case 'p': case 'poor': case 'gray': case 'grey':
			return 0;
		case 'c': case 'common': case 'white':
			return 1;
		case 'u': case 'uncommon': case 'green':
			return 2;
		case 'r': case 's': case 'rare': case 'superior': case 'blue':
			return 3;
		case 'e': case 'epic': case 'purple':
			return 4;
		case 'l': case 'legendary': case 'orange':
			return 5;
		case 'a': case 'artifact':
			return 6;
		case 'h': case 'heirloom':
			return 7;
		}
		return false;
	}

	/**
	 * returns a classIndex
	 * See FrameXML/Constants.lua for the definition
	 *
	 * @param mixed $in class (list) with at least two characters per class
	 *
	 * @return int the classIndex
	 */
	private function _classes($in)
	{
		if (is_numeric($in)) {
			return $in; //a script's passing a classIndex, return it
		}
		$c = strtolower($in);
		if (strpos($c, 'warrior') !== false) {
			return 0; //Warrior
		} elseif (
			(strpos($c, 'dk') !== false) || (stripos($c, 'de') !== false)
		) {
			return 1; //Death Knight
		} elseif (strpos($c, 'pal') !== false) {
			return 2; //Paladin
		} elseif (strpos($c, 'pr') !== false) {
			return 3; //Priest
		} elseif (strpos($c, 'sh') !== false) {
			return 4; //Shaman
		} elseif (strpos($c, 'dr') !== false) {
			return 5; //Druid
		} elseif (strpos($c, 'ro') !== false) {
			return 6; //Rogue
		} elseif (strpos($c, 'mag') !== false) {
			return 7; //Mage
		} elseif (strpos($c, 'lock') !== false) {
			return 8; //Warlock
		} elseif (strpos($c, 'hu') !== false) {
			return 9; //Hunter
		}
		return false;
	}

	/**
	 * returns a raceIndex
	 *
	 * @param mixed $in playable race
	 *
	 * @return int the raceIndex
	 */
	private function _races($in)
	{
		if (is_numeric($in)) {
			return $in; //a script's passing a raceIndex, return it
		}
		$r = strtolower($in);
		if ((strpos($r, 'bl') !== false) || (strpos($r, 'be') !== false)) {
			return 0; //Blood elf
		} elseif (strpos($r, 'dr') !== false) {
			return 1; //Draenei
		} elseif (strpos($r, 'dw') !== false) {
			return 2; //Dwarf
		} elseif (strpos($r, 'gn') !== false) {
			return 3; //Gnome
		} elseif (strpos($r, 'go') !== false) {
			return 4; //Goblin
		} elseif (strpos($r, 'hu') !== false) {
			return 5; //Human
		} elseif (
			(strpos($r, 'ni') !== false) || (strpos($r, 'ne') !== false)
		) {
			return 6; //Night elf
		} elseif (strpos($r, 'or') !== false) {
			return 7; //Orc
		} elseif (strpos($r, 'ta') !== false) {
			return 8; //Tauren
		} elseif (strpos($r, 'tr') !== false) {
			return 9; //Troll
		} elseif (
			(strpos($r, 'un') !== false) || (strpos($r, 'fo') !== false)
		) {
			return 10; //Undead
		} elseif (strpos($r, 'wo') !== false) {
			return 11; //Worgen
		}
		return false;
	}

	/**
	 * returns one of the four item binding types
	 *
	 * @param string $in a bind type in any format
	 *
	 * @return string linked bind type
	 */
	private function _binds($in)
	{
		switch (strtolower($in)) {
		case 'bop': case 'picked': case 'pickup':
		case 'bind on pickup': case 'p':
			return '[[Bind on Pickup|Binds when picked up]]';
		case 'boe': case 'equipped': case 'equip':
		case 'bind on equip': case 'e':
			return '[[Bind on Equip|Binds when equipped]]';
		case 'bou': case 'used': case 'use': case 'bind on use': case 'u':
			return '[[Bind on Use|Binds when used]]';
		case 'bta': case 'account': case 'bind to account': case 'a':
			return '[[Bind to Account|Binds to account]]';
		}
		return false;
	}

	/**
	 * returns one of the many types of armor/weapons
	 *
	 * @param string $in an armor/weapon type
	 *
	 * @return string linked armor/weapon type
	 */
	private function _types($in)
	{
		switch (strtolower($in)) {
		case 'cloth':
			return '[[Cloth armor|Cloth]]';
		case 'leather':
			return '[[Leather armor|Leather]]';
		case 'mail':
			return '[[Mail armor|Mail]]';
		case 'plate':
			return '[[Plate armor|Plate]]';
		case 'fishing': case 'pole': case 'fishingpole': case 'fishing pole':
			return '[[Fishing pole|Fishing Pole]]';
		case 'fist': case 'fist weapon':
			return '[[Fist weapon|Fist Weapon]]';
		case 'libram':
			return '[[Libram (relic)|Libram]]';
		case 'totem':
			return '[[Totem (relic)|Totem]]';
		case 'arrow': case 'axe': case 'bow': case 'bullet':
		case 'crossbow': case 'dagger': case 'gun': case 'idol':
		case 'mace': case 'polearm': case 'shield': case 'staff':
		case 'sword': case 'thrown': case 'wand':
			return '[[' . ucfirst(strtolower($in)) . ']]';
		}
		return false;
	}

	/**
	 * returns a slotID that this item fits in to
	 * There are a few extensions to the slotID listed in the link:
	 * Main Hand: 16. One-Hand: -16. Two-Hand: -15
	 * "Off Hand": 17, "Held in off hand": -17
	 * Ranged: 18. Relic: -18. Thrown: -19
	 *
	 * @param mixed $in paper doll slot by name or ID
	 *
	 * @link http://www.wowwiki.com/InventorySlotID
	 * @return int the slotID
	 */
	private function _slots($in)
	{
		switch (strtolower($in)) {
		case 'main': case 'mainhand': case 'main hand':
		case 'main-hand': case 'mh':
			return 16;
		case 'offhand':	case 'off': case 'off hand':
		case 'off-hand': case 'oh':
			return 17;
		case 'held': case 'held in off-hand': case 'held in off hand':
			return -17;
		case 'onehand': case '1h': case 'one':
		case 'one hand': case 'one-hand':
			return -16;
		case 'twohand': case '2h': case 'two':
		case 'two hand': case 'two-hand':
			return -15;
		case 'back':
			return 15;
		case 'chest':
			return 5;
		case 'feet':
			return 8;
		case 'finger':
			return 11;
		case 'hands':
			return 10;
		case 'head':
			return 1;
		case 'legs':
			return 7;
		case 'neck':
			return 2;
		case 'projectile':
			return 0;
		case 'ranged':
			return 18;
		case 'relic':
			return -18;
		case 'shirt':
			return 4;
		case 'shoulder':
			return 3;
		case 'tabard':
			return 19;
		case 'thrown':
			return -19;
		case 'trinket':
			return 13;
		case 'waist':
			return 6;
		case 'wrist':
			return 9;
		}
		return false;
	}
	/**
	 * returns one of the eight holidays that have items limited to their
	 * duration. The eight holidays are: Love is in the Air, Noblegarden, 
	 * Children's Week, Midsummer, Brewfest, Hallow's End, Pilgrim's Bounty,
	 * and Winter Veil
	 *
	 * @param string $in unvalidated input
	 *
	 * @return string a valid holiday
	 */
	private function _holiday($in)
	{
		$h = strtolower($in);
		if (strpos($h, 'lov') !== false) {
			return 'Love is in the Air';
		} elseif (strpos($h, 'nob') !== false) {
			return 'Noblegarden';
		} elseif (strpos($h, 'chi') !== false) {
			return 'Children\'s Week';
		} elseif (strpos($h, 'mid') !== false) {
			return 'Midsummer';
		} elseif (strpos($h, 'bre') !== false) {
			return 'Brewfest';
		} elseif (strpos($h, 'hal') !== false) {
			return 'Hallow\'s End';
		} elseif (strpos($h, 'pil') !== false) {
			return 'Pilgrim\'s Bounty';
		} elseif (strpos($h, 'win') !== false) {
			return 'Winter Veil';
		}
		return false;
	}

	/**
	 * returns one of the twelve playable profession specializations
	 *
	 * @param string $in profession specialization with at least 3 characters
	 *
	 * @return string unlinked displayable specialization
	 */
	private function _subskill($in)
	{
		$ss = strtolower($in);
		if (strpos($ss, 'gno') !== false) {
			return 'Gnomish Engineering';
		} elseif (strpos($ss, 'gob') !== false) {
			return 'Goblin Engineering';
		} elseif (strpos($ss, 'arm') !== false) {
			return 'Armorsmithing';
		} elseif (strpos($ss, 'axe') !== false) {
			return 'Axesmithing';
		} elseif (strpos($ss, 'ham') !== false) {
			return 'Hammersmithing';
		} elseif (strpos($ss, 'swo') !== false) {
			return 'Swordsmithing';
		} elseif (strpos($ss, 'pot') !== false) {
			return 'Potion Master';
		} elseif (strpos($ss, 'eli') !== false) {
			return 'Elixir Master';
		} elseif (strpos($ss, 'tra') !== false) {
			return 'Transmute Master';
		} elseif (strpos($ss, 'ele') !== false) {
			return 'Elemental Leatherworking';
		} elseif (strpos($ss, 'dra') !== false) {
			return 'Dragonscale Leatherworking';
		} elseif (strpos($ss, 'tri') !== false) {
			return 'Tribal Leatherworking';
		}
		return false;
	}

	/**
	 * returns one of the 18 playable professions
	 *
	 * @param string $in profession with at least 3 characters
	 *
	 * @return string unlinked displayable profession
	 */
	private function _profession($in)
	{
		$pr = strtolower($in);
		if (strpos($pr, 'alc') !== false) {
			return 'Alchemy';
		} elseif (strpos($pr, 'arc') !== false) {
			return 'Archaeology';
		} elseif (strpos($pr, 'bla') !== false) {
			return 'Blacksmithing';
		} elseif (strpos($pr, 'enc') !== false) {
			return 'Enchanting';
		} elseif (strpos($pr, 'eng') !== false) {
			return 'Engineering';
		} elseif ((strpos($pr, 'aid') !== false)
			|| (strpos($pr, 'fir') !== false)) {
			return 'First Aid';
		} elseif (strpos($pr, 'fis') !== false) {
			return 'Fishing';
		} elseif (strpos($pr, 'her') !== false) {
			return 'Herbalism';
		} elseif (strpos($pr, 'ins') !== false) {
			return 'Inscription';
		} elseif (strpos($pr, 'jew') !== false) {
			return 'Jewelcrafting';
		} elseif (strpos($pr, 'lea') !== false) {
			return 'Leatherworking';
		} elseif (strpos($pr, 'loc') !== false) {
			return 'Lockpicking';
		} elseif (strpos($pr, 'min') !== false) {
			return 'Mining';
		} elseif (strpos($pr, 'ref') !== false) {
			return 'Reforging';
		} elseif (strpos($pr, 'rid') !== false) {
			return 'Riding';
		} elseif (strpos($pr, 'run') !== false) {
			return 'Runeforging';
		} elseif (strpos($pr, 'ski') !== false) {
			return 'Skinning';
		} elseif (strpos($pr, 'tai') !== false) {
			return 'Tailoring';
		}
		return false;
	}

	/**
	 * returns one of the eight reputation levels as a standingID
	 *
	 * @param mixed $in a reputation level by name or ID
	 *
	 * @return int the standing ID
	 */
	private function _repRating($in)
	{
		if (is_numeric($in)) {
			return $in; //script passing in a standingID, return it
		}
		switch (strtolower($in)) {
		case 'hated':
			return 1;
		case 'hostile':
			return 2;
		case 'unfriendly': case 'u':
			return 3;
		case 'neutral': case 'n':
			return 4;
		case 'friendly': case 'f':
			return 5;
		case 'honored': case 'h':
			return 6;
		case 'revered': case 'r':
			return 7;
		case 'exalted': case 'e':
			return 8;
		}
		return false;
	}

	/**
	 * returns a specialty bag type
	 *
	 * @param string $in a bag type containing at least 3 characters
	 *
	 * @return string unlinked displayable bag type
	 */
	private function _bagType($in)
	{
		$bt = strtolower($in);
		if (strpos($bt, 'amm') !== false) {
			$bag = 'Ammo Pouch';
		}
		if (strpos($bt, 'enc') !== false) {
			$bag = 'Enchanting Bag';
		}
		if (strpos($bt, 'eng') !== false) {
			$bag = 'Engineering Bag';
		}
		if (strpos($bt, 'her') !== false) {
			$bag = 'Herbalism Bag';
		}
		if (strpos($bt, 'ins') !== false) {
			$bag = 'Inscription Bag';
		}
		if (strpos($bt, 'jew') !== false) {
			$bag = 'Jewelcrafting Bag';
		}
		if (strpos($bt, 'lea') !== false) {
			$bag = 'Leatherworking Bag';
		}
		if (strpos($bt, 'min') !== false) {
			$bag = 'Mining Bag';
		}
		if (strpos($bt, 'qui') !== false) {
			$bag = 'Quiver';
		}
		if (strpos($bt, 'sou') !== false) {
			$bag = 'Soul Bag';
		}
		if (isset($bag)) {
			return $bag;
		}
		return false;
	}

	/**
	 * return one of the five (red/yellow/blue/meta/prismatic) socket types
	 *
	 * @param string $in unformatted socket type
	 *
	 * @return string unlinked displayable socket type
	 */
	private function _socket($in)
	{
		switch(strtolower($in)) {
		case 'b': case 'bl': case 'blue':
			return "Blue";
		case 'r': case 'red':
			return "Red";
		case 'y': case 'yel': case 'yellow':
			return "Yellow";
		case 'm': case 'meta':
			return "Meta";
		case 'c': case 'chr': case 'p': case 'pris':
		case 'chromatic': case 'prismatic':
			return "Prismatic";
		}
		return false;
	}

	/**
	 * key parser error message
	 *
	 * @param string $key   the invalid key
	 * @param string $err   completes the sentence "'$key' is not ____."
	 * @param string $value the value provided for $key
	 *
	 * @return string formatted error message
	 */
	private function _error($key, $err, $value)
	{
		return ";<span class=\"error\">Tooltip error</span>\n: \"$key\" is " .
			"not $err. \"$value\" was provided.\n: See [[Help:Items]] for " .
			"more information.";
	}

	/**
	 * input parser error message
	 *
	 * @param string $message the message the parser wishes to convey
	 * @param string $example an example input that the parser will accept
	 *
	 * @return string formatted error message
	 */
	private function _parseError($message, $example)
	{
		return ";<span class=\"error\">Tooltip parse error</span>\n" .
		": $message\n <code><nowiki>$example</nowiki></code>\n" .
		": See [[Help:Items]] for more information";
	}
}
