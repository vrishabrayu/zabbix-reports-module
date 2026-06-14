<?php

/**
 * @var CView $this
 * @var array $data
 */

$host_data      = $data['host_data'];
$groups         = $data['groups'];
$from           = $data['from'];
$to             = $data['to'];
$groupid        = $data['groupid'];
$total          = count($host_data);
$up             = count(array_filter($host_data, fn($h) => $h['status'] === 'Up'));
$down           = $total - $up;
$avg_sla        = $total > 0 ? round(array_sum(array_column($host_data, 'sla_raw')) / $total, 3) : 100;
$total_problems = array_sum(array_column($host_data, 'problems'));

$filter_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.view');
$export_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.export');

// Build group options
$group_options = [(new CSelectOption(0, '-- All Groups --'))];
foreach ($groups as $g) {
	$group_options[] = (new CSelectOption($g['groupid'], $g['name']))->setSelected($groupid == $g['groupid']);
}

// Build table rows
$rows = [];
$i = 1;
foreach ($host_data as $h) {
	$sla_raw   = $h['sla_raw'];
	$sla_color = $sla_raw >= 99.9 ? '#27ae60' : ($sla_raw >= 99 ? '#e67e22' : '#e74c3c');
	$st_color  = $h['status'] === 'Up' ? '#27ae60' : '#e74c3c';

	$rows[] = new CRow([
		$i++,
		(new CSpan($h['name']))->addStyle('font-weight:bold'),
		$h['ip'],
		$h['group'],
		$h['os'],
		$h['type'],
		(new CSpan($h['status']))->addStyle('color:' . $st_color . ';font-weight:bold'),
		$h['cpu_util'],
		$h['mem_util'],
		$h['uptime'],
		(new CSpan($h['sla']))->addStyle('color:' . $sla_color . ';font-weight:bold'),
		$h['problems'],
		$h['downtime']
	]);
}

$table = (new CTableInfo())
	->setHeader([
		'#', 'Host Name', 'IP Address', 'Group', 'OS', 'Type',
		'Status', 'CPU Util', 'Mem Util', 'Uptime', 'SLA %', 'Problems', 'Downtime'
	]);

foreach ($rows as $row) {
	$table->addRow($row);
}

$page_title = new CTag('h1', true, 'Device & SLA Report');
$page_title->addStyle('margin-bottom: 16px; font-size: 20px; color: #1a1a2e;');

// Filter form
$filter_form = (new CForm('get', 'zabbix.php'))
	->addVar('action', 'customreports.view')
	->addStyle('background:#f5f7fa;border:1px solid #dde3ee;border-radius:6px;padding:14px 16px;margin-bottom:16px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;')
	->addItem([
		(new CDiv([
			(new CLabel('From Date', 'from'))->addStyle('font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			(new CTextBox('from', $from))->setAttribute('type', 'date')->addStyle('border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;')
		])),
		(new CDiv([
			(new CLabel('To Date', 'to'))->addStyle('font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			(new CTextBox('to', $to))->setAttribute('type', 'date')->addStyle('border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;')
		])),
		(new CDiv([
			(new CLabel('Host Group', 'groupid'))->addStyle('font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			(new CSelect('groupid'))
				->addOptions($group_options)
				->setValue($groupid)
				->addStyle('border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;')
		])),
		(new CDiv(
			(new CSubmit('filter_set', 'Apply Filter'))->addStyle('background:#1a1a2e;color:white;border:none;border-radius:4px;padding:6px 16px;cursor:pointer;font-size:13px;height:30px;')
		))->addStyle('padding-top:20px;')
	]);

// Summary cards
$summary = (new CDiv([
	(new CDiv([(new CDiv($total))->addStyle('font-size:24px;font-weight:bold;color:#1a1a2e'), (new CDiv('Total Devices'))->addStyle('font-size:11px;color:#888')]))->addStyle('background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;'),
	(new CDiv([(new CDiv($up))->addStyle('font-size:24px;font-weight:bold;color:#27ae60'), (new CDiv('Devices Up'))->addStyle('font-size:11px;color:#888')]))->addStyle('background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;'),
	(new CDiv([(new CDiv($down))->addStyle('font-size:24px;font-weight:bold;color:#e74c3c'), (new CDiv('Devices Down'))->addStyle('font-size:11px;color:#888')]))->addStyle('background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;'),
	(new CDiv([(new CDiv($avg_sla . '%'))->addStyle('font-size:24px;font-weight:bold;color:#2980b9'), (new CDiv('Avg SLA'))->addStyle('font-size:11px;color:#888')]))->addStyle('background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;'),
	(new CDiv([(new CDiv($total_problems))->addStyle('font-size:24px;font-weight:bold;color:#e67e22'), (new CDiv('Total Problems'))->addStyle('font-size:11px;color:#888')]))->addStyle('background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;')
]))->addStyle('display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;');

// Export buttons
$csv_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'customreports.export')
	->setArgument('format', 'csv')
	->setArgument('from', $from)
	->setArgument('to', $to)
	->setArgument('groupid', $groupid);

$pdf_url = (new CUrl('zabbix.php'))
	->setArgument('action', 'customreports.export')
	->setArgument('format', 'pdf')
	->setArgument('from', $from)
	->setArgument('to', $to)
	->setArgument('groupid', $groupid);

$export_bar = (new CDiv([
	(new CLink('⬇ Export CSV', $csv_url->getUrl()))->addStyle('padding:6px 14px;border-radius:4px;font-size:12px;text-decoration:none;background:#27ae60;color:white;border:none;margin-right:8px;'),
	(new CLink('⬇ Export PDF', $pdf_url->getUrl()))->addStyle('padding:6px 14px;border-radius:4px;font-size:12px;text-decoration:none;background:#e74c3c;color:white;border:none;')->setAttribute('target', '_blank')
]))->addStyle('margin-bottom:12px;');

// Wrap everything
(new CDiv([$page_title, $filter_form, $summary, $export_bar, $table]))
	->addStyle('padding:20px;font-family:Arial,sans-serif;')
	->show();
