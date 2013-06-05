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
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/hosts.inc.php';
require_once dirname(__FILE__).'/include/items.inc.php';


$page['title'] = _('My Latest Data');
$page['file'] = 'mylatest.php';
//$page['hist_arg'] = array('hostid', 'groupid', 'graphid');
$page['hist_arg'] = array('groupid','hostid','show','select','open','applicationid');
//$page['scripts'] = array('class.calendar.js', 'gtlc.js', 'flickerfreescreen.js');
$page['scripts'] = array();
$page['type'] = detect_page_type(PAGE_TYPE_HTML);

define('ZBX_PAGE_DO_JS_REFRESH', 1);

ob_start();

require_once dirname(__FILE__).'/include/page_header.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'groupid' =>	array(T_ZBX_INT, O_OPT, P_SYS, DB_ID,		null),
	'hostid' =>		array(T_ZBX_INT, O_OPT, P_SYS, DB_ID,		null),
	'graphid' =>	array(T_ZBX_INT, O_OPT, P_SYS, DB_ID,		null),
	'period' =>		array(T_ZBX_INT, O_OPT, P_SYS, null,		null),
	'stime' =>		array(T_ZBX_STR, O_OPT, P_SYS, null,		null),
	'action' =>		array(T_ZBX_STR, O_OPT, P_SYS, IN("'go','add','remove'"), null),
	'fullscreen' =>	array(T_ZBX_INT, O_OPT, P_SYS, IN('0,1'),	null),
	// ajax
	'favobj' =>		array(T_ZBX_STR, O_OPT, P_ACT, null,		null),
	'favref' =>		array(T_ZBX_STR, O_OPT, P_ACT, NOT_EMPTY,	null),
	'favid' =>		array(T_ZBX_INT, O_OPT, P_ACT, null,		null),
	'favstate' =>	array(T_ZBX_INT, O_OPT, P_ACT, NOT_EMPTY,	null),
	'favaction' =>	array(T_ZBX_STR, O_OPT, P_ACT, IN("'add','remove'"), null)
);
check_fields($fields);

$pageFilter = new CPageFilter(array(
	'groups' => array('monitored_hosts' => true),
	'hosts' => array('monitored_hosts' => true),
	'groupid' => get_request('groupid', null),
	'hostid' => get_request('hostid', null)//,
));
$_REQUEST['groupid'] = $pageFilter->groupid;
$_REQUEST['hostid'] = $pageFilter->hostid;

/*
 * Ajax
 */
if (isset($_REQUEST['favobj'])) {
	if ($_REQUEST['favobj'] == 'filter') {
		CProfile::update('web.charts.filter.state', $_REQUEST['favstate'], PROFILE_TYPE_INT);
	}
	if ($_REQUEST['favobj'] == 'hat') {
		CProfile::update('web.charts.hats.'.$_REQUEST['favref'].'.state', $_REQUEST['favstate'], PROFILE_TYPE_INT);
	}
	if ($_REQUEST['favobj'] == 'timelinefixedperiod') {
		if (isset($_REQUEST['favid'])) {
			CProfile::update('web.screens.timelinefixed', $_REQUEST['favid'], PROFILE_TYPE_INT);
		}
	}
	if (str_in_array($_REQUEST['favobj'], array('itemid', 'graphid'))) {
		$result = false;
		if ($_REQUEST['favaction'] == 'add') {
			$result = add2favorites('web.favorite.graphids', $_REQUEST['favid'], $_REQUEST['favobj']);
			if ($result) {
				echo '$("addrm_fav").title = "'._('Remove from favourites').'";'."\n";
				echo '$("addrm_fav").onclick = function() { rm4favorites("graphid", "'.$_REQUEST['favid'].'", 0); }'."\n";
			}
		}
		elseif ($_REQUEST['favaction'] == 'remove') {
			$result = rm4favorites('web.favorite.graphids', $_REQUEST['favid'], $_REQUEST['favobj']);

			if ($result) {
				echo '$("addrm_fav").title = "'._('Add to favourites').'";'."\n";
				echo '$("addrm_fav").onclick = function() { add2favorites("graphid", "'.$_REQUEST['favid'].'"); }'."\n";
			}
		}

		if ($page['type'] == PAGE_TYPE_JS && $result) {
			echo 'switchElementsClass("addrm_fav", "iconminus", "iconplus");';
		}
	}
}
if (!empty($_REQUEST['period']) || !empty($_REQUEST['stime'])) {
	CScreenBase::calculateTime(array(
		'profileIdx' => 'web.screens',
		'profileIdx2' => $pageFilter->graphid,
		'updateProfile' => true,
		'period' => get_request('period'),
		'stime' => get_request('stime')
	));

	$curl = new Curl($_SERVER['REQUEST_URI']);
	$curl->removeArgument('period');
	$curl->removeArgument('stime');

	ob_end_clean();
	redirect($curl->getUrl());
}

ob_end_flush();

if ($page['type'] == PAGE_TYPE_JS || $page['type'] == PAGE_TYPE_HTML_BLOCK) {
	require_once dirname(__FILE__).'/include/page_footer.php';
	exit();
}

$data = array(
	'pageFilter' => $pageFilter,
	'fullscreen' => get_request('fullscreen')
);

$options = array(
           	'filter' => array('status' => 0, 'flags' => array(ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED)),
                'output' => array('name', 'lastvalue', 'lastclock', 'value_type', 'units', 'valuemapid'),
                'preservekeys' => true,
		'sortfield' => 'name',
		'selectDiscoveryRule' =>  array('name', 'snmp_oid'),
		'selectHosts' =>  array('host')
        );

if ($_REQUEST['hostid'] != 0) {
        $options['hostids'] = get_request('hostid', null);
}
elseif ($_REQUEST['groupid'] != 0) {
        $options['groupids'] = get_request('groupid', null);
}

$items = API::Item()->get($options);
if (empty($items))
	access_deny();


$data['itemTables'] = array();

//Organizes data
foreach ($items as $item)
{
	$hostid = $item['hosts'][0]['hostid'];
	$partes = explode(' - ', $item['name']);
	$value = formatItemValue($item);


	if (!array_key_exists($hostid, $data['itemTables']))
	{
		$data['itemTables'][$hostid]['host'] = $item['hosts'][0]['host'];
		$data['itemTables'][$hostid]['tables'] = array();
	}

	if (!empty($item['discoveryRule']))
	{
		$table = $item['discoveryRule']['itemid'];
		$name = $item['discoveryRule']['name'];
	}
	else
	{
		$table = 0;
		$name = 'General';
	}

	if (!array_key_exists($table, $data['itemTables'][$hostid]['tables']))
	{
		$data['itemTables'][$hostid]['tables'][$table]['name'] = $name;
		$data['itemTables'][$hostid]['tables'][$table]['rows'] = array();
		if (count($partes) == 2)
//		if ($table != 0)
			$data['itemTables'][$hostid]['tables'][$table]['rows'][0][0] = 'Item';
		else
		{
			$data['itemTables'][$hostid]['tables'][$table]['rows'][0][0] = 'Item';
			$data['itemTables'][$hostid]['tables'][$table]['rows'][0]['Value'] = 'Value';
		}
	}

//	if (($table != 0) && count($partes == 2))
	if (count($partes) == 2)
	{
		if (!array_key_exists($partes[1], $data['itemTables'][$hostid]['tables'][$table]['rows'][0]))
		{
			$data['itemTables'][$hostid]['tables'][$table]['rows'][0][$partes[1]] = $partes[1];
		}
		if (!array_key_exists($partes[0], $data['itemTables'][$hostid]['tables'][$table]['rows']))
		{
			$data['itemTables'][$hostid]['tables'][$table]['rows'][$partes[0]][0] = $partes[0];
		}
		$data['itemTables'][$hostid]['tables'][$table]['rows'][$partes[0]][$partes[1]] = $value;
	}
	else
	{
		array_push($data['itemTables'][$hostid]['tables'][$table]['rows'], array(
										'Item' => $item['name'],
										'Value' => $value
									));
	}
}

/*
 * Display
 */


// render view
$chartsView = new CView('monitoring.mylatest', $data);
$chartsView->render();
$chartsView->show();

//print_r($items);
//print_r($data['itemTables']);

require_once dirname(__FILE__).'/include/page_footer.php';
