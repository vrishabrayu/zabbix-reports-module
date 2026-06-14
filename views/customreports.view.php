<?php declare(strict_types = 1);

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

// Group select using raw CTag (safe approach)
$select = (new CTag('select', true))
	->setAttribute('name', 'groupid')
	->setAttribute('id', 'groupid')
	->setAttribute('style', 'border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;min-width:180px;');

$opt = (new CTag('option', true, '-- All Groups --'))->setAttribute('value', '0');
if ($groupid == 0) $opt->setAttribute('selected', 'selected');
$select->addItem($opt);

foreach ($groups as $g) {
	$opt = (new CTag('option', true, $g['name']))->setAttribute('value', $g['groupid']);
	if ($groupid == $g['groupid']) $opt->setAttribute('selected', 'selected');
	$select->addItem($opt);
}

// Filter form
$filter_form = (new CTag('form', true))
	->setAttribute('method', 'get')
	->setAttribute('action', 'zabbix.php')
	->setAttribute('style', 'background:#f5f7fa;border:1px solid #dde3ee;border-radius:6px;padding:14px 16px;margin-bottom:16px;display:flex;gap:16px;align-items:flex-end;flex-wrap:wrap;')
	->addItem((new CTag('input', false))->setAttribute('type', 'hidden')->setAttribute('name', 'action')->setAttribute('value', 'customreports.view'))
	->addItem(
		(new CDiv([
			(new CTag('label', true, 'From Date'))->setAttribute('style', 'font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			(new CTag('input', false))->setAttribute('type', 'date')->setAttribute('name', 'from')->setAttribute('value', $from)->setAttribute('style', 'border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;')
		]))
	)
	->addItem(
		(new CDiv([
			(new CTag('label', true, 'To Date'))->setAttribute('style', 'font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			(new CTag('input', false))->setAttribute('type', 'date')->setAttribute('name', 'to')->setAttribute('value', $to)->setAttribute('style', 'border:1px solid #c8d0e0;border-radius:4px;padding:5px 8px;font-size:13px;height:30px;')
		]))
	)
	->addItem(
		(new CDiv([
			(new CTag('label', true, 'Host Group'))->setAttribute('style', 'font-size:12px;font-weight:bold;display:block;margin-bottom:4px;'),
			$select
		]))
	)
	->addItem(
		(new CDiv(
			(new CTag('button', true, 'Apply Filter'))
				->setAttribute('type', 'submit')
				->setAttribute('style', 'background:#1a1a2e;color:white;border:none;border-radius:4px;padding:6px 16px;cursor:pointer;font-size:13px;height:30px;')
		))->setAttribute('style', 'padding-top:20px;')
	);

// Summary cards
$card_style = 'background:#fff;border:1px solid #d0d5e0;border-radius:6px;padding:12px 20px;min-width:110px;';
$summary = (new CDiv([
	(new CDiv([(new CDiv((string)$total))->setAttribute('style', 'font-size:24px;font-weight:bold;color:#1a1a2e'), (new CDiv('Total Devices'))->setAttribute('style', 'font-size:11px;color:#888')]))->setAttribute('style', $card_style),
	(new CDiv([(new CDiv((string)$up))->setAttribute('style', 'font-size:24px;font-weight:bold;color:#27ae60'), (new CDiv('Devices Up'))->setAttribute('style', 'font-size:11px;color:#888')]))->setAttribute('style', $card_style),
	(new CDiv([(new CDiv((string)$down))->setAttribute('style', 'font-size:24px;font-weight:bold;color:#e74c3c'), (new CDiv('Devices Down'))->setAttribute('style', 'font-size:11px;color:#888')]))->setAttribute('style', $card_style),
	(new CDiv([(new CDiv($avg_sla . '%'))->setAttribute('style', 'font-size:24px;font-weight:bold;color:#2980b9'), (new CDiv('Avg SLA'))->setAttribute('style', 'font-size:11px;color:#888')]))->setAttribute('style', $card_style),
	(new CDiv([(new CDiv((string)$total_problems))->setAttribute('style', 'font-size:24px;font-weight:bold;color:#e67e22'), (new CDiv('Total Problems'))->setAttribute('style', 'font-size:11px;color:#888')]))->setAttribute('style', $card_style)
]))->setAttribute('style', 'display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;');

// Export buttons
$csv_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.export')->setArgument('format', 'csv')->setArgument('from', $from)->setArgument('to', $to)->setArgument('groupid', $groupid)->getUrl();
$pdf_url = (new CUrl('zabbix.php'))->setArgument('action', 'customreports.export')->setArgument('format', 'pdf')->setArgument('from', $from)->setArgument('to', $to)->setArgument('groupid', $groupid)->getUrl();

$btn_style = 'padding:6px 14px;border-radius:4px;font-size:12px;text-decoration:none;color:white;margin-right:8px;display:inline-block;';
$export_bar = (new CDiv([
	(new CTag('a', true, 'Export CSV'))->setAttribute('href', $csv_url)->setAttribute('style', $btn_style . 'background:#27ae60;'),
	(new CTag('a', true, 'Export PDF'))->setAttribute('href', $pdf_url)->setAttribute('target', '_blank')->setAttribute('style', $btn_style . 'background:#e74c3c;')
]))->setAttribute('style', 'margin-bottom:12px;');

// Table
$table = (new CTableInfo())
	->setHeader(['#', 'Host Name', 'IP Address', 'Group', 'OS', 'Type', 'Status', 'CPU Util', 'Mem Util', 'Uptime', 'SLA %', 'Problems', 'Downtime']);

$i = 1;
foreach ($host_data as $h) {
	$sla_raw   = $h['sla_raw'];
	$sla_color = $sla_raw >= 99.9 ? '#27ae60' : ($sla_raw >= 99 ? '#e67e22' : '#e74c3c');
	$st_color  = $h['status'] === 'Up' ? '#27ae60' : '#e74c3c';

	$table->addRow(new CRow([
		$i++,
		(new CSpan($h['name']))->setAttribute('style', 'font-weight:bold'),
		$h['ip'],
		$h['group'],
		$h['os'],
		$h['type'],
		(new CSpan($h['status']))->setAttribute('style', 'color:' . $st_color . ';font-weight:bold'),
		$h['cpu_util'],
		$h['mem_util'],
		$h['uptime'],
		(new CSpan($h['sla']))->setAttribute('style', 'color:' . $sla_color . ';font-weight:bold'),
		$h['problems'],
		$h['downtime']
	]));
}

(new CHtmlPage())
	->setTitle('Device & SLA Report')
	->addItem($filter_form)
	->addItem($summary)
	->addItem($export_bar)
	->addItem($table)
	->show();
