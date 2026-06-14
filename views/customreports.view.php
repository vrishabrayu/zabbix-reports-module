<?php

$this->addJsFile('jquery.min.js');

(new CWidget())
	->setTitle(_('Device & SLA Report'))
	->addItem(
		(new CFilter())
			->setResetUrl((new CUrl('zabbix.php'))->setArgument('action', 'customreports.view'))
			->addVar('action', 'customreports.view')
			->addFilterTab(_('Filter'), [
				(new CFormGrid())
					->addItem([
						new CLabel(_('From date'), 'from'),
						new CFormField(
							(new CDateSelector('from', $data['from']))
								->setDateFormat(ZBX_DATE)
								->setAttribute('placeholder', _('YYYY-MM-DD'))
						)
					])
					->addItem([
						new CLabel(_('To date'), 'to'),
						new CFormField(
							(new CDateSelector('to', $data['to']))
								->setDateFormat(ZBX_DATE)
								->setAttribute('placeholder', _('YYYY-MM-DD'))
						)
					])
					->addItem([
						new CLabel(_('Host group'), 'groupid'),
						new CFormField(
							(new CSelect('groupid'))
								->setValue($data['groupid'])
								->addOption(new CSelectOption(0, _('All groups')))
								->addOptions(CSelect::createOptionsFromArray(
									array_column($data['groups'], 'name', 'groupid')
								))
						)
					])
			])
	)
	->addItem(
		(new CDiv([
			// Summary cards
			(new CDiv([
				(new CDiv([
					(new CDiv(count($data['host_data'])))->addClass('cr-val'),
					(new CDiv(_('Total Devices')))->addClass('cr-lbl')
				]))->addClass('cr-card'),
				(new CDiv([
					(new CDiv(count(array_filter($data['host_data'], fn($h) => $h['status'] === 'Up'))))->addClass('cr-val green'),
					(new CDiv(_('Devices Up')))->addClass('cr-lbl')
				]))->addClass('cr-card'),
				(new CDiv([
					(new CDiv(count(array_filter($data['host_data'], fn($h) => $h['status'] !== 'Up'))))->addClass('cr-val red'),
					(new CDiv(_('Devices Down')))->addClass('cr-lbl')
				]))->addClass('cr-card'),
				(new CDiv([
					(new CDiv(
						(count($data['host_data']) > 0
							? round(array_sum(array_column($data['host_data'], 'sla_raw')) / count($data['host_data']), 3)
							: 100) . '%'
					))->addClass('cr-val blue'),
					(new CDiv(_('Avg SLA')))->addClass('cr-lbl')
				]))->addClass('cr-card'),
				(new CDiv([
					(new CDiv(array_sum(array_column($data['host_data'], 'problems'))))->addClass('cr-val orange'),
					(new CDiv(_('Total Problems')))->addClass('cr-lbl')
				]))->addClass('cr-card')
			]))->addClass('cr-summary'),

			// Export buttons
			(new CDiv([
				(new CLink(
					_('Export CSV'),
					(new CUrl('zabbix.php'))
						->setArgument('action', 'customreports.export')
						->setArgument('format', 'csv')
						->setArgument('from', $data['from'])
						->setArgument('to', $data['to'])
						->setArgument('groupid', $data['groupid'])
				))->addClass('btn-alt'),
				(new CLink(
					_('Export PDF'),
					(new CUrl('zabbix.php'))
						->setArgument('action', 'customreports.export')
						->setArgument('format', 'pdf')
						->setArgument('from', $data['from'])
						->setArgument('to', $data['to'])
						->setArgument('groupid', $data['groupid'])
				))->addClass('btn-alt')->setAttribute('target', '_blank')
			]))->addClass('cr-export-bar'),

			// Table
			(new CTableInfo())
				->setHeader([
					'#',
					_('Host Name'),
					_('IP Address'),
					_('Group'),
					_('OS'),
					_('Type'),
					_('Status'),
					_('CPU Util'),
					_('Mem Util'),
					_('Uptime'),
					_('SLA %'),
					_('Problems'),
					_('Downtime')
				])
				->setRows(array_map(static function(array $h, int $i): CRow {
					$sla_raw = $h['sla_raw'];
					$sla_style = ($sla_raw >= 99.9) ? 'color: #27ae60; font-weight:bold'
						: (($sla_raw >= 99) ? 'color: #e67e22; font-weight:bold'
						: 'color: #e74c3c; font-weight:bold');
					$status_style = ($h['status'] === 'Up')
						? 'color: #27ae60; font-weight:bold'
						: 'color: #e74c3c; font-weight:bold';

					return (new CRow([
						$i + 1,
						(new CSpan($h['name']))->setAttribute('style', 'font-weight:bold'),
						$h['ip'],
						$h['group'],
						$h['os'],
						$h['type'],
						(new CSpan($h['status']))->setAttribute('style', $status_style),
						$h['cpu_util'],
						$h['mem_util'],
						$h['uptime'],
						(new CSpan($h['sla']))->setAttribute('style', $sla_style),
						$h['problems'],
						$h['downtime']
					]));
				}, $data['host_data'], array_keys($data['host_data'])))
		]))->addClass('cr-wrap')
	)
	->show();
