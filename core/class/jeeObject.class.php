<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../core/php/core.inc.php';

class jeeObject {
	/*     * *************************Attributs****************************** */

	private $id;
	private $name;
	private $father_id = null;
	private $isVisible = 1;
	private $position;
	private $configuration;
	private $display;

	/*     * ***********************Méthodes statiques*************************** */

	public static function byId($_id) {
		if ($_id == '') {
			return;
		}
		$values = array(
			'id' => $_id,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM jeeObject
                WHERE id=:id';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function byName($_name) {
		$values = array(
			'name' => $_name,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM jeeObject
                WHERE name=:name';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function all($_onlyVisible = false) {
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM jeeObject ';
		if ($_onlyVisible) {
			$sql .= ' WhERE isVisible = 1';
		}
		$sql .= ' ORDER BY position,name,father_id';
		return DB::Prepare($sql, array(), DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function root($_all = false, $_onlyVisible = false) {
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM jeeObject
                WHERE father_id IS NULL';
		if ($_onlyVisible) {
			$sql .= ' AND isVisible = 1';
		}
		$sql .= ' ORDER BY position';
		if ($_all === false) {
			$sql .= ' LIMIT 1';
			return DB::Prepare($sql, array(), DB::FETCH_TYPE_ROW, PDO::FETCH_CLASS, __CLASS__);
		}
		return DB::Prepare($sql, array(), DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function buildTree($_jeeObject = null, $_visible = true) {
		$return = array();
		if (!is_object($_jeeObject)) {
			$jeeObject_list = self::root(true, $_visible);
		} else {
			$jeeObject_list = $_jeeObject->getChild($_visible);
		}
		if (is_array($jeeObject_list) && count($jeeObject_list) > 0) {
			foreach ($jeeObject_list as $jeeObject) {
				$return[] = $jeeObject;
				$return = array_merge($return, self::buildTree($jeeObject, $_visible));
			}
		}
		return $return;
	}

	public static function fullData($_restrict = array()) {
		if (isset($_restrict['object']) && !isset($_restrict['jeeObject'])) {
			$_restrict['jeeObject'] = $_restrict['object'];
		}
		$return = array();
		foreach (self::all(true) as $jeeObject) {
			if (!isset($_restrict['jeeObject']) || !is_array($_restrict['jeeObject']) || isset($_restrict['jeeObject'][$jeeObject->getId()])) {
				$jeeObject_return = utils::o2a($jeeObject);
				$jeeObject_return['eqLogics'] = array();
				foreach ($jeeObject->getEqLogic(true, true) as $eqLogic) {
					if (!isset($_restrict['eqLogic']) || !is_array($_restrict['eqLogic']) || isset($_restrict['eqLogic'][$eqLogic->getId()])) {
						$eqLogic_return = utils::o2a($eqLogic);
						$eqLogic_return['cmds'] = array();
						foreach ($eqLogic->getCmd() as $cmd) {
							if (!isset($_restrict['cmd']) || !is_array($_restrict['cmd']) || isset($_restrict['cmd'][$cmd->getId()])) {
								$cmd_return = utils::o2a($cmd);
								if ($cmd->getType() == 'info') {
									$cmd_return['state'] = $cmd->execCmd();
								}
								$eqLogic_return['cmds'][] = $cmd_return;
							}
						}
						$jeeObject_return['eqLogics'][] = $eqLogic_return;
					}
				}
				$return[] = $jeeObject_return;
			}
		}
		return $return;
	}

	public static function searchConfiguration($_search) {
		$values = array(
			'configuration' => '%' . $_search . '%',
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
		FROM jeeObject
		WHERE `configuration` LIKE :configuration';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public static function deadCmd() {
		$return = array();
		foreach (self::all() as $jeeObject) {
			foreach ($jeeObject->getConfiguration('summary', '') as $key => $summary) {
				foreach ($summary as $cmdInfo) {
					if (!cmd::byId(str_replace('#', '', $cmdInfo['cmd']))) {
						$return[] = array('detail' => 'Résumé ' . $jeeObject->getName(), 'help' => config::byKey('jeeObject:summary')[$key]['name'], 'who' => $cmdInfo['cmd']);
					}
				}
			}
		}
		return $return;
	}

	public static function checkSummaryUpdate($_cmd_id) {
		$jeeObjects = self::searchConfiguration('#' . $_cmd_id . '#');
		if (count($jeeObjects) == 0) {
			return;
		}
		$toRefreshCmd = array();
		$global = array();
		foreach ($jeeObjects as $jeeObject) {
			$summaries = $jeeObject->getConfiguration('summary');
			if (!is_array($summaries)) {
				continue;
			}
			$event = array('jeeObject_id' => $jeeObject->getId(), 'keys' => array());
			foreach ($summaries as $key => $summary) {
				foreach ($summary as $cmd_info) {
					preg_match_all("/#([0-9]*)#/", $cmd_info['cmd'], $matches);
					foreach ($matches[1] as $cmd_id) {
						if ($cmd_id == $_cmd_id) {
							$value = $jeeObject->getSummary($key);
							$event['keys'][$key] = array('value' => $value);
							$toRefreshCmd[] = array('key' => $key, 'jeeObject' => $jeeObject, 'value' => $value);
							if ($jeeObject->getConfiguration('summary::global::' . $key, 0) == 1) {
								$global[$key] = 1;
							}
						}
					}
				}
			}
			$events[] = $event;
		}
		if (count($toRefreshCmd) > 0) {
			foreach ($toRefreshCmd as $value) {
				try {
					if ($jeeObject->getConfiguration('summary_virtual_id') == '') {
						continue;
					}
					$virtual = eqLogic::byId($value['jeeObject']->getConfiguration('summary_virtual_id'));
					if (!is_object($virtual)) {
						$jeeObject->getConfiguration('summary_virtual_id', '');
						$jeeObject->save();
						continue;
					}
					$cmd = $virtual->getCmd('info', $value['key']);
					if (!is_object($cmd)) {
						continue;
					}
					$cmd->event($value['value']);
				} catch (Exception $e) {

				}
			}
		}
		if (count($global) > 0) {
			$event = array('jeeObject_id' => 'global', 'keys' => array());
			foreach ($global as $key => $value) {
				try {
					$result = self::getGlobalSummary($key);
					if ($result === null) {
						continue;
					}
					$event['keys'][$key] = array('value' => $result);
					$virtual = eqLogic::byLogicalId('summaryglobal', 'virtual');
					if (!is_object($virtual)) {
						continue;
					}
					$cmd = $virtual->getCmd('info', $key);
					if (!is_object($cmd)) {
						continue;
					}
					$cmd->event($result);
				} catch (Exception $e) {

				}
			}
			$events[] = $event;
		}
		if (count($events) > 0) {
			event::adds('jeeObject::summary::update', $events);
		}
	}

	public static function getGlobalSummary($_key) {
		if ($_key == '') {
			return null;
		}
		$def = config::byKey('jeeObject:summary');
		$jeeObjects = self::all();
		$value = array();
		foreach ($jeeObjects as $jeeObject) {
			if ($jeeObject->getConfiguration('summary::global::' . $_key, 0) == 0) {
				continue;
			}
			$result = $jeeObject->getSummary($_key, true);
			if ($result === null || !is_array($result)) {
				continue;
			}
			$value = array_merge($value, $result);
		}
		if (count($value) == 0) {
			return null;
		}
		if ($def[$_key]['calcul'] == 'text') {
			return trim(implode(',', $value), ',');
		}
		return round(jeedom::calculStat($def[$_key]['calcul'], $value), 1);
	}

	public static function getGlobalHtmlSummary($_version = 'desktop') {
		$jeeObjects = self::all();
		$def = config::byKey('jeeObject:summary');
		$values = array();
		$return = '<span class="jeeObjectSummaryglobal" data-version="' . $_version . '">';
		foreach ($def as $key => $value) {
			foreach ($jeeObjects as $jeeObject) {
				if ($jeeObject->getConfiguration('summary::global::' . $key, 0) == 0) {
					continue;
				}
				if (!isset($values[$key])) {
					$values[$key] = array();
				}
				$result = $jeeObject->getSummary($key, true);
				if ($result === null || !is_array($result)) {
					continue;
				}
				$values[$key] = array_merge($values[$key], $result);
			}
		}
		$margin = ($_version == 'desktop') ? 4 : 2;

		foreach ($values as $key => $value) {
			if (count($value) == 0) {
				continue;
			}
			$style = '';
			$allowDisplayZero = $def[$key]['allowDisplayZero'];
			if ($def[$key]['calcul'] == 'text') {
				$result = trim(implode(',', $value), ',');
				$allowDisplayZero = 1;
			} else {
				$result = round(jeedom::calculStat($def[$key]['calcul'], $value), 1);

			}
			if ($allowDisplayZero == 0 && $result == 0) {
				$style = 'display:none;';
			}
			$return .= '<span class="jeeObjectSummaryParent cursor" data-summary="' . $key . '" data-object_id="" style="margin-right:' . $margin . 'px;' . $style . '" data-displayZeroValue="' . $allowDisplayZero . '">';
			$return .= $def[$key]['icon'] . ' <sup><span class="jeeObjectSummary' . $key . '">' . $result . '</span> ' . $def[$key]['unit'] . '</sup>';
			$return .= '</span>';
		}
		return trim($return) . '</span>';
	}

	public static function createSummaryToVirtual($_key = '') {
		if ($_key == '') {
			return;
		}
		$def = config::byKey('jeeObject:summary');
		if (!isset($def[$_key])) {
			return;
		}
		try {
			$plugin = plugin::byId('virtual');
			if (!is_object($plugin)) {
				$update = update::byLogicalId('virtual');
				if (!is_object($update)) {
					$update = new update();
				}
				$update->setLogicalId('virtual');
				$update->setSource('market');
				$update->setConfiguration('version', 'stable');
				$update->save();
				$update->doUpdate();
				sleep(2);
				$plugin = plugin::byId('virtual');
			}
		} catch (Exception $e) {
			$update = update::byLogicalId('virtual');
			if (!is_object($update)) {
				$update = new update();
			}
			$update->setLogicalId('virtual');
			$update->setSource('market');
			$update->setConfiguration('version', 'stable');
			$update->save();
			$update->doUpdate();
			sleep(2);
			$plugin = plugin::byId('virtual');
		}
		if (!$plugin->isActive()) {
			$plugin->setIsEnable(1);
		}
		if (!is_object($plugin)) {
			throw new Exception(__('Le plugin virtuel doit être installé', __FILE__));
		}
		if (!$plugin->isActive()) {
			throw new Exception(__('Le plugin virtuel doit être actif', __FILE__));
		}

		$virtual = eqLogic::byLogicalId('summaryglobal', 'virtual');
		if (!is_object($virtual)) {
			$virtual = new virtual();
			$virtual->setName(__('Résumé Global', __FILE__));
			$virtual->setIsVisible(0);
			$virtual->setIsEnable(1);
		}
		$virtual->setIsEnable(1);
		$virtual->setLogicalId('summaryglobal');
		$virtual->setEqType_name('virtual');
		$virtual->save();
		$cmd = $virtual->getCmd('info', $_key);
		if (!is_object($cmd)) {
			$cmd = new virtualCmd();
			$cmd->setName($def[$_key]['name']);
			$cmd->setIsHistorized(1);
		}
		$cmd->setEqLogic_id($virtual->getId());
		$cmd->setLogicalId($_key);
		$cmd->setType('info');
		if ($def[$_key]['calcul'] == 'text') {
			$cmd->setSubtype('string');
		} else {
			$cmd->setSubtype('numeric');
		}
		$cmd->setUnite($def[$_key]['unit']);
		$cmd->save();

		foreach (jeeObject::all() as $jeeObject) {
			$summaries = $jeeObject->getConfiguration('summary');
			if (!is_array($summaries)) {
				continue;
			}
			if (!isset($summaries[$_key]) || !is_array($summaries[$_key]) || count($summaries[$_key]) == 0) {
				continue;
			}
			$virtual = eqLogic::byLogicalId('summary' . $jeeObject->getId(), 'virtual');
			if (!is_object($virtual)) {
				$virtual = new virtual();
				$virtual->setName(__('Résumé', __FILE__));
				$virtual->setIsVisible(0);
				$virtual->setIsEnable(1);
			}
			$virtual->setIsEnable(1);
			$virtual->setLogicalId('summary' . $jeeObject->getId());
			$virtual->setEqType_name('virtual');
			$virtual->setJeeObject_id($jeeObject->getId());
			$virtual->save();
			$jeeObject->setConfiguration('summary_virtual_id', $virtual->getId());
			$jeeObject->save();
			$cmd = $virtual->getCmd('info', $_key);
			if (!is_object($cmd)) {
				$cmd = new virtualCmd();
				$cmd->setName($def[$_key]['name']);
				$cmd->setIsHistorized(1);
			}
			$cmd->setEqLogic_id($virtual->getId());
			$cmd->setLogicalId($_key);
			$cmd->setType('info');
			if ($def[$_key]['calcul'] == 'text') {
				$cmd->setSubtype('string');
			} else {
				$cmd->setSubtype('numeric');
			}
			$cmd->setUnite($def[$_key]['unit']);
			$cmd->save();
		}
	}

	/*     * *********************Méthodes d'instance************************* */

	public function checkTreeConsistency($_fathers = array()) {
		$father = $this->getFather();
		if (!is_object($father)) {
			return;
		}
		if (in_array($this->getFather_id(), $_fathers)) {
			throw new Exception(__('Problème dans l\'arbre des objets', __FILE__));
		}
		$_fathers[] = $this->getId();

		$father->checkTreeConsistency($_fathers);
	}

	public function preSave() {
		if (is_numeric($this->getFather_id()) && $this->getFather_id() == $this->getId()) {
			throw new Exception(__('L\'objet ne peut pas être son propre père', __FILE__));
		}
		$this->checkTreeConsistency();
		$this->setConfiguration('parentNumber', $this->parentNumber());
		if ($this->getConfiguration('tagColor') == '') {
			$this->setConfiguration('tagColor', '#000000');
		}
		if ($this->getConfiguration('tagTextColor') == '') {
			$this->setConfiguration('tagTextColor', '#FFFFFF');
		}
		if ($this->getConfiguration('desktop::summaryTextColor') == '') {
			$this->setConfiguration('desktop::summaryTextColor', '');
		}
		if ($this->getConfiguration('mobile::summaryTextColor') == '') {
			$this->setConfiguration('mobile::summaryTextColor', '');
		}
	}

	public function save() {
		return DB::save($this);
	}

	public function getChild($_visible = true) {
		$values = array(
			'id' => $this->id,
		);
		$sql = 'SELECT ' . DB::buildField(__CLASS__) . '
                FROM jeeObject
                WHERE father_id=:id';
		if ($_visible) {
			$sql .= ' AND isVisible=1 ';
		}
		$sql .= ' ORDER BY position';
		return DB::Prepare($sql, $values, DB::FETCH_TYPE_ALL, PDO::FETCH_CLASS, __CLASS__);
	}

	public function getChilds() {
		$return = array();
		foreach ($this->getChild() as $child) {
			$return[] = $child;
			$return = array_merge($return, $child->getChilds());
		}
		return $return;
	}

	public function getEqLogic($_onlyEnable = true, $_onlyVisible = false, $_eqType_name = null, $_logicalId = null) {
		$eqLogics = eqLogic::byJeeObjectId($this->getId(), $_onlyEnable, $_onlyVisible, $_eqType_name, $_logicalId);
		if (is_array($eqLogics)) {
			foreach ($eqLogics as &$eqLogic) {
				$eqLogic->setJeeObject($this);
			}
		}
		return $eqLogics;
	}

	public function getEqLogicBySummary($_summary = '', $_onlyEnable = true, $_onlyVisible = false, $_eqType_name = null, $_logicalId = null) {
		$def = config::byKey('jeeObject:summary');
		if ($_summary == '' || !isset($def[$_summary])) {
			return null;
		}
		$summaries = $this->getConfiguration('summary');
		if (!isset($summaries[$_summary])) {
			return array();
		}
		$eqLogics = eqLogic::byJeeObjectId($this->getId(), $_onlyEnable, $_onlyVisible, $_eqType_name, $_logicalId);
		$eqLogics_id = array();
		foreach ($summaries[$_summary] as $infos) {
			$cmd = cmd::byId(str_replace('#', '', $infos['cmd']));
			if (is_object($cmd)) {
				$eqLogics_id[$cmd->getEqLogic_id()] = $cmd->getEqLogic_id();
			}
		}
		$return = array();
		if (is_array($eqLogics)) {
			foreach ($eqLogics as $eqLogic) {
				if (isset($eqLogics_id[$eqLogic->getId()])) {
					$eqLogic->setJeeObject($this);
					$return[] = $eqLogic;
				}
			}
		}
		return $return;
	}

	public function getScenario($_onlyEnable = true, $_onlyVisible = false) {
		return scenario::byJeeObjectId($this->getId(), $_onlyEnable, $_onlyVisible);
	}

	public function preRemove() {
		dataStore::removeByTypeLinkId('jeeObject', $this->getId());
	}

	public function remove() {
		jeedom::addRemoveHistory(array('id' => $this->getId(), 'name' => $this->getName(), 'date' => date('Y-m-d H:i:s'), 'type' => 'jeeObject'));
		return DB::remove($this);
	}

	public function getFather() {
		return self::byId($this->getFather_id());
	}

	public function parentNumber() {
		$father = $this->getFather();
		if (!is_object($father)) {
			return 0;
		}
		$fatherNumber = 0;
		while ($fatherNumber < 50) {
			$fatherNumber++;
			$father = $father->getFather();
			if (!is_object($father)) {
				return $fatherNumber;
			}
		}
		return 0;
	}

	public function getHumanName($_tag = false, $_prettify = false) {
		if ($_tag) {
			if ($_prettify) {
				if ($this->getDisplay('tagColor') != '') {
					return '<span class="label" style="text-shadow : none;background-color:' . $this->getDisplay('tagColor') . ' !important;color:' . $this->getDisplay('tagTextColor', 'white') . ' !important">' . $this->getDisplay('icon') . ' ' . $this->getName() . '</span>';
				} else {
					return '<span class="label label-primary" style="text-shadow : none;">' . $this->getDisplay('icon') . ' ' . $this->getName() . '</span>';
				}
			} else {
				return $this->getDisplay('icon') . ' ' . $this->getName();
			}
		} else {
			return $this->getName();
		}
	}

	public function getSummary($_key = '', $_raw = false) {
		$def = config::byKey('jeeObject:summary');
		if ($_key == '' || !isset($def[$_key])) {
			return null;
		}
		$summaries = $this->getConfiguration('summary');
		if (!isset($summaries[$_key])) {
			return null;
		}
		$values = array();
		foreach ($summaries[$_key] as $infos) {
			if (isset($infos['enable']) && $infos['enable'] == 0) {
				continue;
			}
			$value = jeedom::evaluateExpression(cmd::cmdToValue($infos['cmd']));
			if (isset($infos['invert']) && $infos['invert'] == 1) {
				$value = !$value;
			}
			if (isset($def[$_key]['count']) && $def[$_key]['count'] == 'binary' && $value > 1) {
				$value = 1;
			}
			$values[] = $value;
		}
		if (count($values) == 0) {
			return null;
		}
		if ($_raw) {
			return $values;
		}
		if ($def[$_key]['calcul'] == 'text') {
			return trim(implode(',', $values), ',');
		}
		return round(jeedom::calculStat($def[$_key]['calcul'], $values), 1);
	}

	public function getHtmlSummary($_version = 'desktop') {
		$return = '<span class="jeeObjectSummary' . $this->getId() . '" data-version="' . $_version . '">';
		foreach (config::byKey('jeeObject:summary') as $key => $value) {
			if ($this->getConfiguration('summary::hide::' . $_version . '::' . $key, 0) == 1) {
				continue;
			}
			$result = $this->getSummary($key);
			if ($result !== null) {
				$style = '';
				if ($_version == 'desktop') {
					$style = 'color:' . $this->getDisplay($_version . '::summaryTextColor', '#000000') . ';';
				}
				$allowDisplayZero = $value['allowDisplayZero'];
				if ($value['calcul'] == 'text') {
					$allowDisplayZero = 1;
				}
				if ($allowDisplayZero == 0 && $result == 0) {
					$style = 'display:none;';
				}
				$return .= '<span style="margin-right:5px;' . $style . '" class="jeeObjectSummaryParent cursor" data-summary="' . $key . '" data-object_id="' . $this->getId() . '" data-displayZeroValue="' . $allowDisplayZero . '">' . $value['icon'] . ' <sup><span class="ojeeOjectSummary' . $key . '">' . $result . '</span> ' . $value['unit'] . '</span></sup>';
			}
		}
		return trim($return) . '</span>';
	}

	public function getLinkData(&$_data = array('node' => array(), 'link' => array()), $_level = 0, $_drill = null) {
		if ($_drill === null) {
			$_drill = config::byKey('graphlink::jeeObject::drill');
		}
		if (isset($_data['node']['jeeObject' . $this->getId()])) {
			return;
		}
		$_level++;
		if ($_level > $_drill) {
			return $_data;
		}
		$icon = findCodeIcon($this->getDisplay('icon'));
		$_data['node']['object' . $this->getId()] = array(
			'id' => 'jeeObject' . $this->getId(),
			'name' => $this->getName(),
			'icon' => $icon['icon'],
			'fontfamily' => $icon['fontfamily'],
			'fontweight' => ($_level == 1) ? 'bold' : 'normal',
			'fontsize' => '4em',
			'texty' => -35,
			'textx' => 0,
			'title' => $this->getHumanName(),
			'url' => 'index.php?v=d&p=jeeObject&id=' . $this->getId(),
		);
		$use = $this->getUse();
		addGraphLink($this, 'jeeObject', $this->getEqLogic(), 'eqLogic', $_data, $_level, $_drill, array('dashvalue' => '1,0', 'lengthfactor' => 0.6));
		addGraphLink($this, 'jeeObject', $use['cmd'], 'cmd', $_data, $_level, $_drill);
		addGraphLink($this, 'jeeObject', $use['scenario'], 'scenario', $_data, $_level, $_drill);
		addGraphLink($this, 'jeeObject', $use['eqLogic'], 'eqLogic', $_data, $_level, $_drill);
		addGraphLink($this, 'jeeObject', $use['dataStore'], 'dataStore', $_data, $_level, $_drill);
		addGraphLink($this, 'jeeObject', $this->getChild(), 'jeeObject', $_data, $_level, $_drill, array('dashvalue' => '1,0', 'lengthfactor' => 0.6));
		addGraphLink($this, 'jeeObject', $this->getScenario(), 'scenario', $_data, $_level, $_drill, array('dashvalue' => '1,0', 'lengthfactor' => 0.6));
		return $_data;
	}

	public function getUse() {
		$json = jeedom::fromHumanReadable(json_encode(utils::o2a($this)));
		return jeedom::getTypeUse($json);
	}

	/*     * **********************Getteur Setteur*************************** */

	public function getId() {
		return $this->id;
	}

	public function getName() {
		return $this->name;
	}

	public function getFather_id($_default = null) {
		if ($this->father_id == '' || !is_numeric($this->father_id)) {
			return $_default;
		}
		return $this->father_id;
	}

	public function getIsVisible($_default = null) {
		if ($this->isVisible == '' || !is_numeric($this->isVisible)) {
			return $_default;
		}
		return $this->isVisible;
	}

	public function setId($id) {
		$this->id = $id;
		return $this;
	}

	public function setName($name) {
		$name = str_replace(array('&', '#', ']', '[', '%'), '', $name);
		$this->name = $name;
		return $this;
	}

	public function setFather_id($father_id = null) {
		$this->father_id = ($father_id == '') ? null : $father_id;
		return $this;
	}

	public function setIsVisible($isVisible) {
		$this->isVisible = $isVisible;
		return $this;
	}

	public function getPosition($_default = null) {
		if ($this->position == '' || !is_numeric($this->position)) {
			return $_default;
		}
		return $this->position;
	}

	public function setPosition($position) {
		$this->position = $position;
		return $this;
	}

	public function getConfiguration($_key = '', $_default = '') {
		return utils::getJsonAttr($this->configuration, $_key, $_default);
	}

	public function setConfiguration($_key, $_value) {
		$this->configuration = utils::setJsonAttr($this->configuration, $_key, $_value);
		return $this;
	}

	public function getDisplay($_key = '', $_default = '') {
		return utils::getJsonAttr($this->display, $_key, $_default);
	}

	public function setDisplay($_key, $_value) {
		$this->display = utils::setJsonAttr($this->display, $_key, $_value);
		return $this;
	}

	public function getCache($_key = '', $_default = '') {
		return utils::getJsonAttr(cache::byKey('jeeObjectCacheAttr' . $this->getId())->getValue(), $_key, $_default);
	}

	public function setCache($_key, $_value = null) {
		cache::set('jeeObjectCacheAttr' . $this->getId(), utils::setJsonAttr(cache::byKey('jeeObjectCacheAttr' . $this->getId())->getValue(), $_key, $_value));
	}

}
