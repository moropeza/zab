<?php
/*
** Zabbix
** Copyright (C) 2001-2013 Zabbix SIA
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


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/maps.inc.php';
require_once dirname(__FILE__).'/include/services.inc.php';

$page['title'] = _('Map To Triggers');
$page['file'] = 'map2triggers.php';
$page['hist_arg'] = array('sysmapid');
$page['type'] = detect_page_type(PAGE_TYPE_HTML);

if ($page['type'] == PAGE_TYPE_HTML) {
	define('ZBX_PAGE_DO_REFRESH', 1);
}

require_once dirname(__FILE__).'/include/page_header.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'sysmapid' =>		array(T_ZBX_INT, O_OPT, P_SYS|P_NZERO,	DB_ID,					null),
	'mapname' =>		array(T_ZBX_STR, O_OPT, P_SYS,			null,					null),
	'severity_min' =>	array(T_ZBX_INT, O_OPT, P_SYS,			IN('0,1,2,3,4,5'),		null),
	'fullscreen' =>		array(T_ZBX_INT, O_OPT, P_SYS,			IN('0,1'),				null),
	'favobj' =>			array(T_ZBX_STR, O_OPT, P_ACT,			null,					null),
	'favref' =>			array(T_ZBX_STR, O_OPT, P_ACT,			NOT_EMPTY,				null),
	'favid' =>			array(T_ZBX_INT, O_OPT, P_ACT,			null,					null),
	'favstate' =>		array(T_ZBX_INT, O_OPT, P_ACT,			NOT_EMPTY,				null),
	'favaction' =>		array(T_ZBX_STR, O_OPT, P_ACT,			IN("'add','remove'"),	null)
);
check_fields($fields);

/*
 * Ajax
 */
if (isset($_REQUEST['favobj'])) {
	if ($_REQUEST['favobj'] == 'sysmapid') {
		$result = false;

		if ($_REQUEST['favaction'] == 'add') {
			$result = CFavorite::add('web.favorite.sysmapids', $_REQUEST['favid'], $_REQUEST['favobj']);
			if ($result) {
				echo '$("addrm_fav").title = "'._('Remove from favourites').'";'."\n".
					'$("addrm_fav").onclick = function() { rm4favorites("sysmapid", "'.$_REQUEST['favid'].'", 0); }'."\n";
			}
		}
		elseif ($_REQUEST['favaction'] == 'remove') {
			$result = CFavorite::remove('web.favorite.sysmapids', $_REQUEST['favid'], $_REQUEST['favobj']);
			if ($result) {
				echo '$("addrm_fav").title = "'._('Add to favourites').'";'."\n".
					'$("addrm_fav").onclick = function() { add2favorites("sysmapid", "'.$_REQUEST['favid'].'"); }'."\n";
			}
		}

		if ($page['type'] == PAGE_TYPE_JS && $result) {
			echo 'switchElementsClass("addrm_fav", "iconminus", "iconplus");';
		}
	}
}

if ($page['type'] == PAGE_TYPE_JS || $page['type'] == PAGE_TYPE_HTML_BLOCK) {
	require_once dirname(__FILE__).'/include/page_footer.php';
	exit();
}

/*
 * Permissions
 */
$maps = API::Map()->get(array(
	'output' => array('sysmapid', 'name'),
	'nodeids' => get_current_nodeid(),
	'preservekeys' => true
));
order_result($maps, 'name');

if ($mapName = get_request('mapname')) {
	unset($_REQUEST['sysmapid']);

	foreach ($maps as $map) {
		if ($map['name'] === $mapName) {
			$_REQUEST['sysmapid'] = $map['sysmapid'];
		}
	}
}
elseif (empty($_REQUEST['sysmapid'])) {
	$_REQUEST['sysmapid'] = CProfile::get('web.maps.sysmapid');

	if (!$_REQUEST['sysmapid'] && !isset($maps[$_REQUEST['sysmapid']])) {
		if ($firstMap = reset($maps)) {
			$_REQUEST['sysmapid'] = $firstMap['sysmapid'];
		}
	}
}

if (isset($_REQUEST['sysmapid']) && !isset($maps[$_REQUEST['sysmapid']])) {
	access_deny();
}

CProfile::update('web.maps.sysmapid', $_REQUEST['sysmapid'], PROFILE_TYPE_ID);

/*
 * Display
 */
$data = array(
	'fullscreen' => $_REQUEST['fullscreen'],
	'sysmapid' => $_REQUEST['sysmapid'],
	'maps' => $maps,
	'services' => array(),
	'triggers' => array(),
);

$data['map'] = API::Map()->get(array(
	'output' => API_OUTPUT_EXTEND,
	'sysmapids' => $data['sysmapid'],
	'expandUrls' => true,
	'selectSelements' => API_OUTPUT_EXTEND,
	'selectLinks' => API_OUTPUT_EXTEND,
	'preservekeys' => true
));
$data['map'] = reset($data['map']);

$data['pageFilter'] = new CPageFilter(array(
	'severitiesMin' => array(
		'default' => $data['map']['severity_min'],
		'mapId' => $data['sysmapid']
	),
	'severityMin' => get_request('severity_min')
));
$data['severity_min'] = $data['pageFilter']->severityMin;

	// Fetches all defined IT services
$data['itServices'] = API::Service()->get(array(
                'output' => array('serviceid', 'name', 'triggerid'),
                'selectParent' => array('serviceid'),
                'preservekeys' => true
        ));
//print_r($data['itServices']);


	// Fetches Zabbix server's hostid
//$zabbixServer = API::Host()->get(array(
//	'output' => API_OUTPUT_SHORTEN,
//	'filter' => array('host' => "Zabbix server")
//));

	// Fetches all triggers elements in the map

$parents = array();
$map_ends = array();
foreach ($data['map']['selements'] as $element)
{
	if ($element['elementtype'] == 2)
	{
		// Fetches the description and host name of triggers in the map
		$new_trigger = API::Trigger()->get(array(
			'triggerids' => $element['elementid'],
		'output' => array('description'),
				'expandData' => true
			));

		$location = API::Host()->get(array(
			'hostids' => $new_trigger[0]['hostid'],
			//'output' => API_OUTPUT_SHORTEN,
			'selectInventory' => array('location'),
			));
		if (isset($location[0]['inventory']['location']))
			$location = $location[0]['inventory']['location'];
		else
			$location = '';

		$data['triggers'][$element['elementid']] = array(
				'triggerid' => $element['elementid'],
				'selementid' => $element['selementid'],
				'name' => $new_trigger[0]['host'] . ":" . $new_trigger[0]['description'],
				'hostid' => $new_trigger[0]['hostid'],
				'location' => $location
			);

	}
	elseif (($element['elementtype'] == 4) && (preg_match("/(.+)>>>/", $element['label'], $match)))
	{
		$service_starts[$match[1]] = $element['selementid'];
		$data['services'][$match[1]] = array();
		$map_ends[$match[1]] = array();
		$parents[] = array(
			'map_seq' => array($element['selementid']),
			'trigger_seq' => array(),
			'service' => $match[1],
			'P' => '',
			'N' => array(''),
			'L1' => array(''),
			'L2' => array(''),
			'L3' => array('')
		);
	}
	elseif (($element['elementtype'] == 4) && (preg_match("/(.+)<<</", $element['label'], $match)))
	{
		$service_ends[$match[1]] = $element['selementid'];
	}
}

//print_r($map_ends);
//print_r($parents);

while (!empty($parents))
//for ($n=0;$n<20;$n++)
{
	$newparents = array();
	foreach ($parents as $parent)
	{
		$childs = array();
		//$filter = false;
		foreach ($data['map']['links'] as $link) //Search for childs
		{
			$proto_child = $parent; // Child inherits all parents properties

			$found = true;
			if ($link['selementid1'] == $parent['map_seq'][0])
				array_unshift($proto_child['map_seq'], $link['selementid2']);
			elseif ($link['selementid2'] == $parent['map_seq'][0])
				array_unshift($proto_child['map_seq'], $link['selementid1']);
			else
				$found = false;

			if (!$found || in_array($proto_child['map_seq'][0], $service_starts))
				continue;

			if (in_array($proto_child['map_seq'][0], $service_ends))
			{
				$serv_end = array_search($proto_child['map_seq'][0], $service_ends);
				if ($proto_child['service'] == $serv_end)
				{
					if (!in_array($proto_child['map_seq'][1], $map_ends[$serv_end]))
						$map_ends[$serv_end][] = $proto_child['map_seq'][1];
				}
				else
					continue;
			}

			// A valid prototype child was found
			array_unshift($proto_child['N'], $proto_child['N'][0]);
			array_unshift($proto_child['L1'], $proto_child['L1'][0]);
			array_unshift($proto_child['L2'], $proto_child['L2'][0]);
			array_unshift($proto_child['L3'], $proto_child['L3'][0]);

				// Determines if the child is repeated in the path
			$keys = array_keys($proto_child['map_seq'], $proto_child['map_seq'][0]);
			array_shift($keys);
//print_r($keys);
			$repeated = false;
			foreach ($keys as $key)
			{
//echo "\r\nL2[0]:" . $proto_child['L2'][0] . " L2[key]: " . $proto_child['L2'][$key]; 
				if ($proto_child['L2'][0] == $proto_child['L2'][$key])
				{
					$repeated = true;
//					echo "\r\nRepetido";
				}
			}
			if ($repeated) continue;

			if ($data['map']['selements'][$proto_child['map_seq'][1]]['elementtype'] == 2)
				$proto_child['P'] = array($data['triggers'][$data['map']['selements'][$proto_child['map_seq'][1]]['elementid']]['name']);
			else
				$proto_child['P'] = array($data['map']['selements'][$proto_child['map_seq'][1]]['label']);

			// Tests default condition
			if ($proto_child['N'][0] != "")
			{
				if ($data['map']['selements'][$proto_child['map_seq'][0]]['elementtype'] == 2)
					$N = $data['triggers'][$data['map']['selements'][$proto_child['map_seq'][0]]['elementid']]['name'];
				else
					$N = $data['map']['selements'][$proto_child['map_seq'][0]]['label'];

				// echo "\r\n Proto:\r\n";
				// print_r($proto_child['N'][0]);
				// echo "\r\n NLabel: " . $N;

				if (preg_match("#" . $proto_child['N'][0] . "#", $N))
					$proto_child['N'][0] = "";
				else
					continue;
			}

//			echo "\r\nProto: ". $proto_child['map_seq'][0] . " name: " .$data['triggers'][$data['map']['selements'][$proto_child['map_seq'][0]]['elementid']]['name'];;
		//	echo "\r\nlabel: " . $data['map']['selements'][$proto_child['map_seq'][0]]['label'];

			// Processes each routing rule defined on the map element label
			$rules = explode("----------", $data['map']['selements'][$proto_child['map_seq'][0]]['label']);
			if (count($rules) > 1)
				$rules = explode(";", $rules[1]);
			else
				$rules = array("");

			// if (!empty($rules) && ($rules != array("\r\n")))
			foreach ($rules as $rule)
			{
				$child = $proto_child;

				// echo "\r\nrule: ";
				// print_r($rule);

				$parts = explode(">", $rule);
				$conditions = explode("&", $parts[0]);
				if (count($parts) > 1)
					$operations = explode(",", $parts[1]);
				else
					$operations = array();

				$success = true;

				if ($success && !empty($conditions) && ($conditions != array("")) && ($conditions != array("\r\n")))
					foreach ($conditions as $condition)
					{
						preg_match("#(P|N|L[123])=(.+)#", $condition, $matches);

				/*	echo "\r\ncondition: \r\n";
						print_r($condition);
						echo "\r\nmatches:  \r\n";
						print_r($matches);
						echo "\r\nc1\r\n";
						print_r($matches[2]);
						echo "\r\nc2\r\n";
						print_r($child[$matches[1]][0]);*/

						if (!preg_match("#" . $matches[2] . "#", $child[$matches[1]][0]))
						{
							// echo "\r\nRaspao";
							$success = false;
							break;
						}
					}

				if (!$success)
					continue;

				if (!empty($operations) && ($operations != array("")) && ($operations != array("\r\n")))
				foreach ($operations as $operation)
				{
					// echo "\r\noperation: ";
					// print_r($operation);
					if (preg_match("#(N|L[123])=(.*)#", $operation, $matches))
						$child[$matches[1]][0] = $matches[2];
					// print_r($child);
				}

				//echo "\r\nPASO";
				$childs[] = $child;
			}
		}

		// print_r($childs);

		foreach ($childs as $child)	// Processes each child
		{

/*if (in_array("SNR.FXS", $child['L3']))
{
	echo "\r\n *************************************************";
	foreach ($child['map_seq'] as $key => $seq)
	{
		if ($data['map']['selements'][$seq]['elementtype'] == 2)
			echo "\r\nkey: " . $key . "\t N: " . $child['N'][$key] . ", L1: " . $child['L1'][$key] . ", L2: " . $child['L2'][$key] . ", L3: " . $child['L3'][$key] . ", Map: " . $seq . " label " . $data['triggers'][$data['map']['selements'][$seq]['elementid']]['name'];
		else
			echo "\r\nkey: " . $key . "\t N: " . $child['N'][$key] . ", L1: " . $child['L1'][$key] . ", L2: " . $child['L2'][$key] . ", L3: " . $child['L3'][$key] . ", Map: " . $seq . " label " . $data['map']['selements'][$seq]['label'];
	}
	echo "\r\n *************************************************";
}*/

			if ($data['map']['selements'][$child['map_seq'][0]]['elementtype'] == 2)
				array_unshift($child['trigger_seq'], $data['map']['selements'][$child['map_seq'][0]]['elementid']);

			if ((preg_match("/Trigger/", $child['service']))
				&& ($data['map']['selements'][$child['map_seq'][0]]['elementtype'] == 2))
					$map = $child['map_seq'][0];
			elseif ((!preg_match("/Trigger/", $child['service']))
				&& (in_array($child['map_seq'][0], $service_ends)))
					$map = $child['map_seq'][1];
			else
				unset($map);

			if (isset($map))
			{
				if (!array_key_exists($map, $data['services'][$child['service']]))
				{
					$data['services'][$child['service']][$map] = array(
							'label' => $data['map']['selements'][$map]['label'],
							'trigger_down' => $child['trigger_seq'][0],
							'service' => "",
							'location' => array(),
							'paths' => array()
						);
				}

				$data['services'][$child['service']][$map]['paths'][] = $child['trigger_seq'];
			}

			if (!in_array($child['map_seq'][0], $service_ends))
				$newparents[] = $child;
		}
	}
	$parents = $newparents;
}

// print_r($data['services']);

//	Deletes redundant routes
foreach ($data['services'] as $service => $depends)
{
	foreach ($depends as $map => $depend)
	{
			// Removes routes that doesn't ends in predefined destinations (if any)
		if ((!empty($map_ends[$service])) && (!in_array($map, $map_ends[$service])))
		{
			unset($data['services'][$service][$map]);
			continue;
		}

			// Merges redundant routes, leaves only common path
		$commons = array();
		foreach ($depend['paths'] as $path_id => $path)
		{
			$border =  end($path);
			if (!array_key_exists($border, $commons))
			{
				$commons[$border] = $path;
			}
			else
			{
				$commons[$border] = array_intersect($path, $commons[$border]);
			}
			unset($data['services'][$service][$map]['paths'][$path_id]);
		}
		$data['services'][$service][$map]['paths'] = $commons;


		if (preg_match("/Trigger/", $service))
                {
			foreach ($data['services'][$service][$map]['paths'] as $path)
				if (count($path) > 1)
				{
					array_shift($path);
					$data['services'][$service][$map]['ups'][] = array_shift($path);
				}
                }
		else
		{
			if ($data['map']['selements'][$map]['elementtype'] != 2)
			{
				$location = $data['map']['selements'][$map]['label'];
			}
			else
			{
				$location = $data['triggers'][$data['map']['selements'][$map]['elementid']]['location'];
			}

			$data['services'][$service][$map]['location'] = $location;

			$serv = explode(".", $service);
			$data['services'][$service][$map]['service'] = $serv[0];

		}
	}
 }

// render view
$mapsView = new CView('administration.map2triggers', $data);
$mapsView->render();
$mapsView->show();

require_once dirname(__FILE__).'/include/page_footer.php';
