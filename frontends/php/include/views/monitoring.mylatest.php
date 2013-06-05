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


$chartsWidget = new CWidget('hat_mylatest');
$chartForm = new CForm('get');
$chartForm->addVar('fullscreen', $this->data['fullscreen']);
$chartForm->addItem(array(_('Group').SPACE, $this->data['pageFilter']->getGroupsCB(true)));
$chartForm->addItem(array(SPACE._('Host').SPACE, $this->data['pageFilter']->getHostsCB(true)));

$chartsWidget->addFlicker(new CDiv(null, null, 'scrollbar_cntr'), CProfile::get('web.charts.filter.state', 1));
$chartsWidget->addPageHeader(_('My Latest Data'), array(
	get_icon('fullscreen', array('fullscreen' => $this->data['fullscreen']))
));
$chartsWidget->addHeader(
	'HHOLLAAA',
	$chartForm
);
$chartsWidget->addItem(BR());

foreach ($data['itemTables'] as $hostTables)
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
                		$header = array(new CCol($row[0], 'center'));
		                foreach ($row as $j => $col) {
					if ($j !== 0)
	 	  	                     	$header[] = new CCol($col, 'vertical_rotation');
                		}
		                $dataTable->setHeader($header, 'vertical_header');
			}
			else
				$dataTable->addRow($row);
		}

		$titleTable->addRow($dataTable);
		$hostTable->addRow($titleTable);
	}
	$chartsWidget->addItem($hostTable);
}


return $chartsWidget;
