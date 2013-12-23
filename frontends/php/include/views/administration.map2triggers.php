<?php
/*
** Zabbix
** Copyright (C) 2000-2012 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
**/


require_once dirname(__FILE__).'/js/general.script.confirm.js.php';
require_once dirname(__FILE__).'/js/monitoring.maps.js.php';

$mapWidget = new CWidget('hat_maps');
$mapTable = new CTable(_('No maps defined.'), 'map map-container');
$mapTable->setAttribute('style', 'margin-top: 4px;');

foreach($this->data['services'] as $service => $depends)
{
	$serviceTable = new CTableInfo(_('No data'));
	$serviceTable->setHeader(array(
	        $service,
	));

	if (preg_match("/Trigger/", $service))
	{
		$table = new CTableInfo(_('No data'));
		$table->setHeader(array(
        		_('Trigger Down'),
	       		_('Trigger Up')
		));

		foreach($depends as $depend)
		{
			$table->addRow(array(
					$this->data['triggers'][$depend['trigger_down']]['name'],
					''
				));

			API::Trigger()->deleteDependencies(array('triggerid' => $depend['trigger_down']));

			if (isset($depend['ups']))
			foreach($depend['ups'] as $up)
			{
				API::Trigger()->deleteDependencies(array('triggerid' => $up));
				$table->addRow(array(
					'',
					$this->data['triggers'][$up]['name'],
				));
			}
		}
	} else {
		$table = new CTableInfo(_('No data'));
		$table->setHeader(array(
        		_('Location'),
	       		_('Triggers')
		));
		foreach($depends as $depend)
		{
			$table->addRow(array(
					$depend['location'],
					''
				));

			foreach($depend['paths'] as $trigger_up => $path)
			{
				foreach ($path as $trig)
				{
					$table->addRow(array(
						'',
						$this->data['triggers'][$trig]['name'],
					));
				}
			}
		}
	}
	$serviceTable->addRow($table);
	$mapWidget->addItem($serviceTable);
}

/*
foreach($this->data['services'] as $service => $depends)
{
	foreach($depends as $depend)
	{
		if (preg_match("/Trigger/", $service))
		{
			if (isset($depend['ups']))
			foreach($depend['ups'] as $up)
				API::Trigger()->addDependencies(array(
					'triggerid' => $depend['trigger_down'],
					'dependsOnTriggerid' => $up
				));
		} else {
			foreach($depend['paths'] as $path)
				foreach ($path as $trig)
					$this->data['triggers'][$trig]['comments'][$depend['service']][] = $depend['location'];
		}
	}
}

$itservicestokeep = array();
foreach($this->data['services'] as $service => $depends)
{
	if (preg_match("/Trigger/", $service))
		continue;


	$filter = array(
			'parent' => array(),
                	'name' => $service
        	);
	$itservice = mapCheckITService($filter, $service, 0, $this->data['itServices']);
	$itservicestokeep[] = $itservice;
	foreach ($depends as $depend)
	{
		$filter = array(
				'parent' => array('serviceid' => $itservice),
				'name' => $depend['location']
			);
		$itlocation = mapCheckITService($filter, $depend['location'], 0, $this->data['itServices']);
		$itservicestokeep[] = $itlocation;

		foreach ($depend['paths'] as $path)
			foreach ($path as $trig)
			{
				$filter = array(
						'parent' => array('serviceid' => $itlocation),
						'triggerid' => $trig
					);
				$ittrigger = mapCheckITService($filter, $this->data['triggers'][$trig]['name'], $trig, $this->data['itServices']);
				$itservicestokeep[] = $ittrigger;
			}
	}
}

do
{
	$todelete = array_diff(array_keys($this->data['itServices']), $itservicestokeep);
	$real = array();
	foreach ($todelete as $delete)
	{
		if (empty($this->data['itServices'][$delete]['dependencies']))
			$real[] = $delete;
	}

	if (!empty($real))
		API::Service()->delete($real);
} while(!empty($todelete));

			foreach ($this->data['triggers'] as $triggerid => $trigger)
			{
				if (isset($trigger['comments']))
				{
				$dbcomment = API::Trigger()->get(array(
					'triggerids' => $triggerid,
					'output' => array('comments')
					));

				$dbcomment = $dbcomment[0]['comments'];

				$new_comment = ">>>>> Servicios afectados <<<<<";

				foreach ($trigger['comments'] as $service => $locations)
				{
					$new_comment .= "\r\n\r\n" . $service;
					asort($locations);
					foreach ($locations as $location)
						$new_comment .= "\r\n -" . $location;
				}


	//			$dbcomment = preg_replace("/(.*)(>>>>> Servicios afectados <<<<<)?/", "$1" . $new_comment, $dbcomment);

			//	if (preg_match("/>>>>> Servicios afectados <<<<</", $dbcomment))
			//		$dbcomment = preg_replace("/(.*)(>>>>> Servicios afectados <<<<<)/", "$1" . $new_comment, $dbcomment);
			//	else
					$dbcomment = $new_comment;

				API::Trigger()->update(array(
					'triggerid' => $triggerid,
					'comments' => $dbcomment
				));
				}

			}

*/

$icon = $fsIcon = null;

if (!empty($this->data['maps'])) {
	$mapComboBox = new CComboBox('sysmapid', get_request('sysmapid', 0), 'submit()');
	foreach ($this->data['maps'] as $sysmapId => $map) {
		$mapComboBox->addItem($sysmapId, get_node_name_by_elid($sysmapId, null, ': ').$map['name']);
	}

	$headerForm = new CForm('get');
	$headerForm->addVar('fullscreen', $this->data['fullscreen']);
	$headerForm->addItem($mapComboBox);

	$mapWidget->addHeader($this->data['map']['name'], $headerForm);

	// get map parent maps
	$parentMaps = array();
	foreach (getParentMaps($this->data['sysmapid']) as $parent) {
		// check for permissions
		if (isset($this->data['maps'][$parent['sysmapid']])) {
			$parentMaps[] = SPACE.SPACE;
			$parentMaps[] = new Clink($parent['name'], 'maps.php?sysmapid='.$parent['sysmapid'].'&fullscreen='.$this->data['fullscreen']);
		}
	}
	if (!empty($parentMaps)) {
		array_unshift($parentMaps, _('Upper level maps').':');
		$mapWidget->addHeader($parentMaps);
	}

	$actionMap = getActionMapBySysmap($this->data['map']);

	$mapTable->addRow($actionMap);

	$imgMap = new CImg('map.php?sysmapid='.$this->data['sysmapid']);
	$imgMap->setMap($actionMap->getName());
	$mapTable->addRow($imgMap);

	$icon = get_icon('favourite', array(
		'fav' => 'web.favorite.sysmapids',
		'elname' => 'sysmapid',
		'elid' => $this->data['sysmapid']
	));
$fsIcon = get_icon('fullscreen', array('fullscreen' => $this->data['fullscreen']));
}

//$mapWidget->addItem($serviceTable);
$mapWidget->addPageHeader(_('NETWORK MAPS'), array($icon, SPACE, $fsIcon));

return $mapWidget;
