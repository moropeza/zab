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

$page['title'] = _('My Latest data');
$page['file'] = 'mylatest.php';
$page['hist_arg'] = array('groupid','hostid','show','select','open','applicationid');
$page['type'] = detect_page_type(PAGE_TYPE_HTML);

define('ZBX_PAGE_MAIN_HAT','hat_latest');

if (PAGE_TYPE_HTML == $page['type']) {
	define('ZBX_PAGE_DO_REFRESH', 1);
}

require_once dirname(__FILE__).'/include/page_header.php';

//		VAR			     			 TYPE	   OPTIONAL FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'apps'=>				array(T_ZBX_INT, O_OPT,	NULL,	DB_ID,		NULL),
	'groupid'=>				array(T_ZBX_INT, O_OPT,	P_SYS,	DB_ID,		NULL),
	'hostid'=>				array(T_ZBX_INT, O_OPT,	P_SYS,	DB_ID,		NULL),

	'fullscreen'=>			array(T_ZBX_INT, O_OPT,	P_SYS,	IN('0,1'),	NULL),
// filter
	'select'=>				array(T_ZBX_STR, O_OPT, NULL,	NULL,		NULL),
	'show_without_data'=>	array(T_ZBX_INT, O_OPT, NULL,	IN('0,1'),	NULL),
	'show_details'=>		array(T_ZBX_INT, O_OPT, NULL,	IN('0,1'),	NULL),
	'filter_rst'=>			array(T_ZBX_INT, O_OPT,	P_SYS,	IN('0,1'),	NULL),
	'filter_set'=>			array(T_ZBX_STR, O_OPT,	P_SYS,	null,		NULL),
//ajax
	'favobj'=>				array(T_ZBX_STR, O_OPT, P_ACT,	NULL,		NULL),
	'favref'=>				array(T_ZBX_STR, O_OPT, P_ACT,  NULL,		NULL),
	'favstate'=>			array(T_ZBX_INT, O_OPT, P_ACT,  NULL,		NULL),
	'toggle_ids'=>			array(T_ZBX_STR, O_OPT, P_ACT,  NULL,		NULL),
	'toggle_open_state'=>	array(T_ZBX_INT, O_OPT, P_ACT,  NULL,		NULL)
);
check_fields($fields);

/*
 * Permissions
 */
if (getRequest('groupid') && !API::HostGroup()->isReadable(array($_REQUEST['groupid']))) {
	access_deny();
}
if (getRequest('hostid') && !API::Host()->isReadable(array($_REQUEST['hostid']))) {
	access_deny();
}

/*
 * Ajax
 */
if (hasRequest('favobj')) {
	if ($_REQUEST['favobj'] == 'filter') {
		CProfile::update('web.latest.filter.state',$_REQUEST['favstate'], PROFILE_TYPE_INT);
	}
	elseif ($_REQUEST['favobj'] == 'toggle') {
		// $_REQUEST['toggle_ids'] can be single id or list of ids,
		// where id xxxx is application id and id 0_xxxx is 0_ + host id
		if (!is_array($_REQUEST['toggle_ids'])) {
			if ($_REQUEST['toggle_ids'][1] == '_') {
				$hostId = substr($_REQUEST['toggle_ids'], 2);
				CProfile::update('web.latest.toggle_other', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $hostId);
			}
			else {
				$applicationId = $_REQUEST['toggle_ids'];
				CProfile::update('web.latest.toggle', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $applicationId);
			}
		}
		else {
			foreach ($_REQUEST['toggle_ids'] as $toggleId) {
				if ($toggleId[1] == '_') {
					$hostId = substr($toggleId, 2);
					CProfile::update('web.latest.toggle_other', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $hostId);
				}
				else {
					$applicationId = $toggleId;
					CProfile::update('web.latest.toggle', $_REQUEST['toggle_open_state'], PROFILE_TYPE_INT, $applicationId);
				}
			}
		}
	}
}

if((PAGE_TYPE_JS == $page['type']) || (PAGE_TYPE_HTML_BLOCK == $page['type'])){
	require_once dirname(__FILE__).'/include/page_footer.php';
	exit();
}

require_once dirname(__FILE__).'/include/views/js/monitoring.latest.js.php';

/*
 * Filter
 */
$filterSelect = getRequest('select');
$filterShowWithoutData = getRequest('show_without_data', 0);
$filterShowDetails = getRequest('show_details', 0);

if (hasRequest('filter_rst')) {
	$filterSelect = '';
	$filterShowWithoutData = 0;
	$filterShowDetails = 0;
}

if (hasRequest('filter_set') || hasRequest('filter_rst')) {
	CProfile::update('web.latest.filter.select', $filterSelect, PROFILE_TYPE_STR);
	CProfile::update('web.latest.filter.show_without_data', $filterShowWithoutData, PROFILE_TYPE_INT);
	CProfile::update('web.latest.filter.show_details', $filterShowDetails, PROFILE_TYPE_INT);
}
else {
	$filterSelect = CProfile::get('web.latest.filter.select', '');
	$filterShowWithoutData = CProfile::get('web.latest.filter.show_without_data', 0);
	$filterShowDetails = CProfile::get('web.latest.filter.show_details', 0);
}

$pageFilter = new CPageFilter(array(
	'groups' => array(
		'real_hosts' => true
	),
	'hosts' => array(
		'with_monitored_items' => true
	),
	'hostid' => getRequest('hostid', null),
	'groupid' => getRequest('groupid', null)
));
$_REQUEST['groupid'] = $pageFilter->groupid;
$_REQUEST['hostid'] = $pageFilter->hostid;

validate_sort_and_sortorder('i.name', ZBX_SORT_UP);

$sortField = getPageSortField();
$sortOrder = getPageSortOrder();

$applications = $items = $hostScripts = array();

// get hosts
if ($_REQUEST['hostid']) {
	$availableHostIds = array($_REQUEST['hostid']);
}
elseif ($pageFilter->hostsSelected) {
	$availableHostIds = array_keys($pageFilter->hosts);
}
else {
	$availableHostIds = array();
}

$hosts = API::Host()->get(array(
	'output' => array('name', 'hostid', 'status'),
	'hostids' => $availableHostIds,
	'with_monitored_items' => true,
	'preservekeys' => true
));
if ($hosts) {
	foreach ($hosts as &$host) {
		$host['item_cnt'] = 0;
	}
	unset($host);

	if (count($hosts) > 1) {
		$sortFields = ($sortField == 'h.name') ? array(array('field' => 'name', 'order' => $sortOrder)) : array('name');
		CArrayHelper::sort($hosts, $sortFields);
	}
}

// get items
if ($hosts) {
	$items = API::Item()->get(array(
		'hostids' => array_keys($hosts),
		'output' => array('itemid', 'name', 'type', 'value_type', 'units', 'hostid', 'state', 'valuemapid', 'status',
			'error', 'trends', 'history', 'delay', 'key_', 'flags'),
		'selectApplications' => array('applicationid'),
		'selectItemDiscovery' => array('ts_delete'),
		'selectDiscoveryRule' =>  array('name', 'snmp_oid'),
		'webitems' => true,
		'filter' => array(
			'status' => array(ITEM_STATUS_ACTIVE)
		),
		'preservekeys' => true
	));
}
if ($items) {
	// filter items by name
	foreach ($items as $key => &$item) {
		$item['resolvedName'] = itemName($item);

		if (!zbx_empty($filterSelect) && !zbx_stristr($item['resolvedName'], $filterSelect)) {
			unset($items[$key]);
		}
	}
	unset($item);

	if ($items) {
		// get history
		$history = Manager::History()->getLast($items, 2);

		// filter items without history
		if (!$filterShowWithoutData) {
			foreach ($items as $key => $item) {
				if (!isset($history[$item['itemid']])) {
					unset($items[$key]);
				}
			}
		}
	}

	if ($items) {
		$hostIds = array_keys(array_flip(zbx_objectValues($items, 'hostid')));

		// add item last update date for sorting
		foreach ($items as &$item) {
			if (isset($history[$item['itemid']])) {
				$item['lastclock'] = $history[$item['itemid']][0]['clock'];
			}
		}
		unset($item);

		// sort
		if ($sortField == 'i.name') {
			$sortFields = array(array('field' => 'resolvedName', 'order' => $sortOrder), 'itemid');
		}
		elseif ($sortField == 'i.lastclock') {
			$sortFields = array(array('field' => 'lastclock', 'order' => $sortOrder), 'resolvedName', 'itemid');
		}
		else {
			$sortFields = array('resolvedName', 'itemid');
		}
		CArrayHelper::sort($items, $sortFields);

		// get applications
		$applications = API::Application()->get(array(
			'output' => API_OUTPUT_EXTEND,
			'hostids' => $hostIds,
			'preservekeys' => true
		));
		if ($applications) {
			foreach ($applications as &$application) {
				$application['hostname'] = $hosts[$application['hostid']]['name'];
				$application['item_cnt'] = 0;
			}
			unset($application);

			// by default order by application name and application id
			$sortFields = ($sortField == 'h.name') ? array(array('field' => 'hostname', 'order' => $sortOrder)) : array();
			array_push($sortFields, 'name', 'applicationid');
			CArrayHelper::sort($applications, $sortFields);
		}

		if ($_REQUEST['hostid'] == 0) {
			// get host scripts
			$hostScripts = API::Script()->getScriptsByHosts($hostIds);

			// get templates screen count
			$screens = API::TemplateScreen()->get(array(
				'hostids' => $hostIds,
				'countOutput' => true,
				'groupCount' => true
			));
			foreach ($screens as $screen) {
				$hosts[$screen['hostid']]['screens'] = $screen['rowscount'];
			}
		}
	}
}

$Tables=array();

//Organizes data

//print_r($items);

foreach ($items as $item)
{
        $lastHistory = isset($history[$item['itemid']][0]) ? $history[$item['itemid']][0] : null;
        $prevHistory = isset($history[$item['itemid']][1]) ? $history[$item['itemid']][1] : null;

        if (strpos($item['units'], ',') !== false) {
                list($item['units'], $item['unitsLong']) = explode(',', $item['units']);
        }
        else {
                $item['unitsLong'] = '';
        }

        // last check time and last value
        if ($lastHistory) {
                $lastClock = zbx_date2str(_('d M Y H:i:s'), $lastHistory['clock']);
                $value = formatHistoryValue($lastHistory['value'], $item, false);
        }
        else {
                $lastClock = UNKNOWN_VALUE;
                $value = UNKNOWN_VALUE;
        }

        $hostid = $item['hostid'];
        $parts = explode(' - ', $item['resolvedName']);

        if (!array_key_exists($hostid, $Tables))
        {
                $Tables[$hostid]['host'] = $hosts[$hostid]['name'];
                $Tables[$hostid]['tables'] = array(
				0 => array('name' => 'General', 'rows' => array(
					0 => array(0 => 'Name', 'Value' => 'Value')
					))
				);
        }

        if (!empty($item['discoveryRule']))
        {
                $tableid = $item['discoveryRule']['itemid'];
                $name = $item['discoveryRule']['name'];
        } else {
                $tableid = 0;
                $name = 'General';
        }

        if (!array_key_exists($tableid, $Tables[$hostid]['tables']))
        {
                $Tables[$hostid]['tables'][$tableid]['name'] = $name;
                $Tables[$hostid]['tables'][$tableid]['rows'] = array();
                if (count($parts) == 2)
//              if ($tableid != 0)
                        $Tables[$hostid]['tables'][$tableid]['rows'][0][0] = 'Item';
        }

//      if (($tableid != 0) && count($parts == 2))
        if (count($parts) == 2)
        {
                if (!array_key_exists($parts[1], $Tables[$hostid]['tables'][$tableid]['rows'][0]))
                {
                        $Tables[$hostid]['tables'][$tableid]['rows'][0][$parts[1]] = $parts[1];
                }
                if (!array_key_exists($parts[0], $Tables[$hostid]['tables'][$tableid]['rows']))
                {
                        $Tables[$hostid]['tables'][$tableid]['rows'][$parts[0]][0] = $parts[0];
                }
                $Tables[$hostid]['tables'][$tableid]['rows'][$parts[0]][$parts[1]] = $value;
        }
        else
        {
                array_push($Tables[$hostid]['tables'][$tableid]['rows'], array(
                                                                                'Item' => $item['name'],
                                                                                'Value' => $value
                                                                        ));
        }
}

//print_r($Tables);


/*
 * Display
 */
$mylatestWidget = new CWidget(null, 'latest-mon');
//$mylatestWidget = new CWidget(null, 'mylatest-mon');

$form = new CForm('get');
$form->addItem(array(_('Group').SPACE, $pageFilter->getGroupsCB(true)));
$form->addItem(array(SPACE._('Host').SPACE, $pageFilter->getHostsCB(true)));

$mylatestWidget->addHeader(_('Items'), $form);

$filterForm = new CFormTable(null, null, 'get');
$filterForm->setAttribute('name',' zbx_filter');
$filterForm->setAttribute('id', 'zbx_filter');
$filterForm->addRow(_('Show items with name like'), new CTextBox('select', $filterSelect, 20));
$filterForm->addRow(_('Show items without data'), new CCheckBox('show_without_data', $filterShowWithoutData, null, 1));
$filterForm->addRow(_('Show details'), new CCheckBox('show_details', $filterShowDetails, null, 1));
$filterForm->addItemToBottomRow(new CSubmit('filter_set', _('Filter')));
$filterForm->addItemToBottomRow(new CButton('filter_rst', _('Reset'), 'javascript: var uri = new Curl(location.href); uri.setArgument("filter_rst", 1); location.href = uri.getUrl();'));

$mylatestWidget->addFlicker($filterForm, CProfile::get('web.latest.filter.state', 1));
$mylatestWidget->addPageHeader(_('MY LATEST DATA'), get_icon('fullscreen', array('fullscreen' => $_REQUEST['fullscreen'])));

//$table = new CTableInfo(_('No values found.'));

/*
$link = new CCol(new CDiv(null, 'app-list-toggle-all icon-plus-9x9'));

// table headers
$hostHeader = make_sorting_header(_('Host'), 'h.name');
$hostHeader->addClass('latest-host');
$lastCheckHeader = make_sorting_header(_('Last check'), 'i.lastclock');
$lastCheckHeader->addClass('latest-lastcheck');
$itemHeader = make_sorting_header(_('Name'), 'i.name');
$itemHeader->addClass('latest-item');

if ($filterShowDetails) {
	$config = select_config();

	$table->addClass('latest-details');
	$table->setHeader(array(
		$link,
		is_show_all_nodes() ? make_sorting_header(_('Node'), 'h.hostid') : null,
		($_REQUEST['hostid'] == 0) ? $hostHeader : null,
		$itemHeader,
		new CSpan(_('Interval')),
		new CSpan(_('History')),
		new CSpan(_('Trends')),
		new CSpan(_('Type')),
		$lastCheckHeader,
		new CSpan(_('Last value')),
		new CSpan(_x('Change', 'noun in latest data')),
		new CCol(SPACE, 'latest-actions'),
		new CCol(new CSpan(_('Error')), 'latest-error')
	));
}
else {
	$table->setHeader(array(
		$link,
		is_show_all_nodes() ? $hostHeader : null,
		($_REQUEST['hostid'] == 0) ? make_sorting_header(_('Host'), 'h.name') : null,
		$itemHeader,
		$lastCheckHeader,
		new CSpan(_('Last value')),
		new CSpan(_x('Change', 'noun in latest data')),
		new CCol(SPACE, 'latest-actions')
	));
}

$tab_rows = array();

foreach ($items as $key => $item){
	if (!$item['applications']) {
		continue;
	}

	$lastHistory = isset($history[$item['itemid']][0]) ? $history[$item['itemid']][0] : null;
	$prevHistory = isset($history[$item['itemid']][1]) ? $history[$item['itemid']][1] : null;

	if (strpos($item['units'], ',') !== false) {
		list($item['units'], $item['unitsLong']) = explode(',', $item['units']);
	}
	else {
		$item['unitsLong'] = '';
	}

	// last check time and last value
	if ($lastHistory) {
		$lastClock = zbx_date2str(_('d M Y H:i:s'), $lastHistory['clock']);
		$lastValue = formatHistoryValue($lastHistory['value'], $item, false);
	}
	else {
		$lastClock = UNKNOWN_VALUE;
		$lastValue = UNKNOWN_VALUE;
	}

	// change
	$digits = ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT) ? 2 : 0;
	if ($lastHistory && $prevHistory
			&& ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64)
			&& (bcsub($lastHistory['value'], $prevHistory['value'], $digits) != 0)) {

		$change = '';
		if (($lastHistory['value'] - $prevHistory['value']) > 0) {
			$change = '+';
		}

		// for 'unixtime' change should be calculated as uptime
		$change .= convert_units(array(
			'value' => bcsub($lastHistory['value'], $prevHistory['value'], $digits),
			'units' => $item['units'] == 'unixtime' ? 'uptime' : $item['units']
		));
		$change = nbsp($change);
	}
	else {
		$change = UNKNOWN_VALUE;
	}

	if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
		$actions = new CLink(_('Graph'), 'history.php?action=showgraph&itemid='.$item['itemid']);
	}
	else {
		$actions = new CLink(_('History'), 'history.php?action=showvalues&itemid='.$item['itemid']);
	}

	$stateCss = ($item['state'] == ITEM_STATE_NOTSUPPORTED) ? 'unknown txt' : 'txt';
	$itemName = $item['resolvedName'];

	if ($filterShowDetails) {
		$itemKey = ($item['type'] == ITEM_TYPE_HTTPTEST || $item['flags'] == ZBX_FLAG_DISCOVERY_CREATED)
			? new CSpan(resolveItemKeyMacros($item), 'enabled')
			: new CLink(resolveItemKeyMacros($item), 'items.php?form=update&itemid='.$item['itemid'], 'enabled');

		$statusIcons = array();
		if ($item['status'] == ITEM_STATUS_ACTIVE) {
			if (zbx_empty($item['error'])) {
				$error = new CDiv(SPACE, 'status_icon iconok');
			}
			else {
				$error = new CDiv(SPACE, 'status_icon iconerror');
				$error->setHint($item['error'], '', 'on');
			}
			$statusIcons[] = $error;
		}

		if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
			$trendValue = $config['hk_trends_global'] ? $config['hk_trends'] : $item['trends'];
		}
		else {
			$trendValue = UNKNOWN_VALUE;
		}

		$row = new CRow(array(
			SPACE,
			is_show_all_nodes() ? SPACE : null,
			($_REQUEST['hostid'] > 0) ? null : SPACE,
			new CCol(new CDiv(array($itemName, BR(), $itemKey), $stateCss.' item')),
			new CCol(new CDiv(
				($item['type'] == ITEM_TYPE_SNMPTRAP || $item['type'] == ITEM_TYPE_TRAPPER)
					? UNKNOWN_VALUE
					: $item['delay'],
				$stateCss
			)),
			new CCol(new CDiv($config['hk_history_global'] ? $config['hk_history'] : $item['history'], $stateCss)),
			new CCol(new CDiv($trendValue, $stateCss)),
			new CCol(new CDiv(item_type2str($item['type']), $stateCss)),
			new CCol(new CDiv($lastClock, $stateCss)),
			new CCol(new CDiv($lastValue, $stateCss)),
			new CCol(new CDiv($change, $stateCss)),
			new CCol($actions, 'latest-actions'),
			new CCol($statusIcons)
		));
	}
	else {
		$row = new CRow(array(
			SPACE,
			is_show_all_nodes() ? SPACE : null,
			($_REQUEST['hostid'] > 0) ? null : SPACE,
			new CCol(new CDiv($itemName, $stateCss.' item')),
			new CCol(new CDiv($lastClock, $stateCss)),
			new CCol(new CDiv($lastValue, $stateCss)),
			new CCol(new CDiv($change, $stateCss)),
			new CCol($actions, 'latest-actions'),
		));
	}

	// add the item row to each application tab
	foreach ($item['applications'] as $itemApplication) {
		$applicationId = $itemApplication['applicationid'];

		$applications[$applicationId]['item_cnt']++;
		$tab_rows[$applicationId][] = $row;
	}

	// remove items with applications from the collection
	unset($items[$key]);
}

foreach ($applications as $appid => $dbApp) {
	$host = $hosts[$dbApp['hostid']];

	if(!isset($tab_rows[$appid])) continue;

	$appRows = $tab_rows[$appid];

	$openState = CProfile::get('web.latest.toggle', null, $dbApp['applicationid']);

	$toggle = new CDiv(SPACE, 'app-list-toggle icon-plus-9x9');
	if ($openState) {
		$toggle->addClass('icon-minus-9x9');
	}
	$toggle->setAttribute('data-app-id', $dbApp['applicationid']);
	$toggle->setAttribute('data-open-state', $openState);

	$hostName = null;

	if ($_REQUEST['hostid'] == 0) {
		$hostName = new CSpan($host['name'],
			'link_menu menu-host'.(($host['status'] == HOST_STATUS_NOT_MONITORED) ? ' not-monitored' : '')
		);
		$hostName->setMenuPopup(getMenuPopupHost($host, $hostScripts[$host['hostid']]));
	}

	// add toggle row
	$table->addRow(array(
		$toggle,
		get_node_name_by_elid($dbApp['applicationid']),
		$hostName,
		new CCol(array(
				bold($dbApp['name']),
				SPACE.'('._n('%1$s Item', '%1$s Items', $dbApp['item_cnt']).')'
			), null, $filterShowDetails ? 10 : 5)
	), 'odd_row');

	// add toggle sub rows
	foreach ($appRows as $row) {
		$row->setAttribute('parent_app_id', $dbApp['applicationid']);
		$row->addClass('odd_row');
		if (!$openState) {
			$row->addClass('hidden');
		}
		$table->addRow($row);
	}
}

/**
 * Display OTHER ITEMS (which are not linked to application)
 *//*
$tab_rows = array();
foreach ($items as $item) {
	$lastHistory = isset($history[$item['itemid']][0]) ? $history[$item['itemid']][0] : null;
	$prevHistory = isset($history[$item['itemid']][1]) ? $history[$item['itemid']][1] : null;

	if (strpos($item['units'], ',') !== false)
		list($item['units'], $item['unitsLong']) = explode(',', $item['units']);
	else
		$item['unitsLong'] = '';

	// last check time and last value
	if ($lastHistory) {
		$lastClock = zbx_date2str(_('d M Y H:i:s'), $lastHistory['clock']);
		$lastValue = formatHistoryValue($lastHistory['value'], $item, false);
	}
	else {
		$lastClock = UNKNOWN_VALUE;
		$lastValue = UNKNOWN_VALUE;
	}

	// column "change"
	$digits = ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT) ? 2 : 0;
	if (isset($lastHistory['value']) && isset($prevHistory['value'])
			&& ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64)
			&& (bcsub($lastHistory['value'], $prevHistory['value'], $digits) != 0)) {

		$change = '';
		if (($lastHistory['value'] - $prevHistory['value']) > 0) {
			$change = '+';
		}

		// for 'unixtime' change should be calculated as uptime
		$change .= convert_units(array(
			'value' => bcsub($lastHistory['value'], $prevHistory['value'], $digits),
			'units' => $item['units'] == 'unixtime' ? 'uptime' : $item['units']
		));
		$change = nbsp($change);
	}
	else {
		$change = ' - ';
	}

	// column "action"
	if (($item['value_type'] == ITEM_VALUE_TYPE_FLOAT) || ($item['value_type'] == ITEM_VALUE_TYPE_UINT64)) {
		$actions = new CLink(_('Graph'), 'history.php?action=showgraph&itemid='.$item['itemid']);
	}
	else{
		$actions = new CLink(_('History'), 'history.php?action=showvalues&itemid='.$item['itemid']);
	}

	$stateCss = ($item['state'] == ITEM_STATE_NOTSUPPORTED) ? 'unknown txt' : 'txt';

	$itemName = $item['resolvedName'];

	$host = $hosts[$item['hostid']];
	if ($filterShowDetails) {
		$itemKey = ($item['type'] == ITEM_TYPE_HTTPTEST || $item['flags'] == ZBX_FLAG_DISCOVERY_CREATED)
			? new CSpan(resolveItemKeyMacros($item), 'enabled')
			: new CLink(resolveItemKeyMacros($item), 'items.php?form=update&itemid='.$item['itemid'], 'enabled');

		$statusIcons = array();
		if ($item['status'] == ITEM_STATUS_ACTIVE) {
			if (zbx_empty($item['error'])) {
				$error = new CDiv(SPACE, 'status_icon iconok');
			}
			else {
				$error = new CDiv(SPACE, 'status_icon iconerror');
				$error->setHint($item['error'], '', 'on');
			}
			$statusIcons[] = $error;
		}

		if ($item['value_type'] == ITEM_VALUE_TYPE_FLOAT || $item['value_type'] == ITEM_VALUE_TYPE_UINT64) {
			$trendValue = $config['hk_trends_global'] ? $config['hk_trends'] : $item['trends'];
		}
		else {
			$trendValue = UNKNOWN_VALUE;
		}

		$row = new CRow(array(
			SPACE,
			is_show_all_nodes() ? ($host['item_cnt'] ? SPACE : get_node_name_by_elid($item['itemid'])) : null,
			$_REQUEST['hostid'] ? null : SPACE,
			new CCol(new CDiv(array($itemName, BR(), $itemKey), $stateCss.' item')),
			new CCol(new CDiv(
				($item['type'] == ITEM_TYPE_SNMPTRAP || $item['type'] == ITEM_TYPE_TRAPPER)
					? UNKNOWN_VALUE
					: $item['delay'],
				$stateCss
			)),
			new CCol(new CDiv($config['hk_history_global'] ? $config['hk_history'] : $item['history'], $stateCss)),
			new CCol(new CDiv($trendValue, $stateCss)),
			new CCol(new CDiv(item_type2str($item['type']), $stateCss)),
			new CCol(new CDiv($lastClock, $stateCss)),
			new CCol(new CDiv($lastValue, $stateCss)),
			new CCol(new CDiv($change, $stateCss)),
			$actions,
			new CCol($statusIcons)
		));
	}
	else {
		$row = new CRow(array(
			SPACE,
			is_show_all_nodes() ? ($host['item_cnt'] ? SPACE : get_node_name_by_elid($item['itemid'])) : null,
			$_REQUEST['hostid'] ? null : SPACE,
			new CCol(new CDiv($itemName, $stateCss.' item')),
			new CCol(new CDiv($lastClock, $stateCss)),
			new CCol(new CDiv($lastValue, $stateCss)),
			new CCol(new CDiv($change, $stateCss)),
			$actions
		));
	}

	$hosts[$item['hostid']]['item_cnt']++;
	$tab_rows[$item['hostid']][] = $row;
}

foreach ($hosts as $hostId => $dbHost) {
	$host = $hosts[$dbHost['hostid']];

	if(!isset($tab_rows[$hostId])) {
		continue;
	}
	$appRows = $tab_rows[$hostId];

	$openState = CProfile::get('web.latest.toggle_other', null, $host['hostid']);

	$toggle = new CDiv(SPACE, 'app-list-toggle icon-plus-9x9');
	if ($openState) {
		$toggle->addClass('icon-minus-9x9');
	}
	$toggle->setAttribute('data-app-id', '0_'.$host['hostid']);
	$toggle->setAttribute('data-open-state', $openState);

	$hostName = null;

	if ($_REQUEST['hostid'] == 0) {
		$hostName = new CSpan($host['name'],
			'link_menu menu-host'.(($host['status'] == HOST_STATUS_NOT_MONITORED) ? ' not-monitored' : '')
		);
		$hostName->setMenuPopup(getMenuPopupHost($host, $hostScripts[$host['hostid']]));
	}

	// add toggle row
	$table->addRow(array(
		$toggle,
		get_node_name_by_elid($dbHost['hostid']),
		$hostName,
		new CCol(
			array(
				bold('- '.('other').' -'),
				SPACE.'('._n('%1$s Item', '%1$s Items', $dbHost['item_cnt']).')'
			),
			null, $filterShowDetails ? 10 : 5
		)
	), 'odd_row');

	// add toggle sub rows
	foreach($appRows as $row) {
		$row->setAttribute('parent_app_id', '0_'.$host['hostid']);
		$row->addClass('odd_row');
		if (!$openState) {
			$row->addClass('hidden');
		}
		$table->addRow($row);
	}
}*/

//$mylatestWidget->addItem(BR());


$itemHeader = make_sorting_header(_('Name'), 'i.name');
//$itemHeader->addClass('latest-item');

foreach ($Tables as $hostTables)
{
        $hostTable = new CTableInfo(_('No data.'));
        $hostTable->setHeader(new Ccol($hostTables['host'], 'center'));

        foreach ($hostTables['tables'] as $table)
        {
                $titleTable = new CTableInfo(_('No data.'));
                $titleTable->setHeader(new Ccol($table['name'], 'center'));

                $dataTable = new CTableInfo(_('No data.'));
                $dataTable->makeVerticalRotation();

                foreach ($table['rows'] as $n => $row)
                {
                        if ($n === 0)
                        {
                                // header
                                //$header = array(new CCol($row[0], 'center'));
				$header = array($itemHeader);
                                foreach ($row as $j => $col) {
                                        if ($j !== 0)
                                                $header[] = new CCol($col, 'wraptext');
                                }
                                $dataTable->setHeader($header, 'vertical_header');
                        }
                        else
                                $dataTable->addRow($row);
                }

                $titleTable->addRow($dataTable);
                $hostTable->addRow($titleTable);
        }
        $mylatestWidget->addItem($hostTable);
}


//$mylatestWidget->addItem($table);
$mylatestWidget->show();

require_once dirname(__FILE__).'/include/page_footer.php';
