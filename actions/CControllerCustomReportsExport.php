<?php

class CControllerCustomReportsExport extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'format'  => 'in csv,pdf',
			'from'    => 'string',
			'to'      => 'string',
			'groupid' => 'id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_DEFAULT_ACCESS);
	}

	protected function doAction(): void {
		$format  = $this->getInput('format', 'csv');
		$from    = $this->getInput('from', date('Y-m-d', strtotime('-30 days')));
		$to      = $this->getInput('to', date('Y-m-d'));
		$groupid = $this->getInput('groupid', 0);

		$host_filter = [
			'output'           => ['hostid', 'host', 'name', 'status'],
			'selectInterfaces' => ['ip'],
			'selectGroups'     => ['name'],
			'selectInventory'  => ['os', 'type'],
			'monitored_hosts'  => true,
			'sortfield'        => 'name'
		];
		if ($groupid) {
			$host_filter['groupids'] = [$groupid];
		}

		$hosts = API::Host()->get($host_filter);
		$time_from = strtotime($from . ' 00:00:00');
		$time_to   = strtotime($to . ' 23:59:59');
		$total_seconds = $time_to - $time_from;

		$rows = [];
		foreach ($hosts as $host) {
			$hostid = $host['hostid'];
			$ip = !empty($host['interfaces']) ? $host['interfaces'][0]['ip'] : '';
			$group_name = !empty($host['groups']) ? $host['groups'][0]['name'] : 'N/A';

			$cpu_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'system.cpu.util'], 'limit' => 1]);
			$cpu_util = !empty($cpu_items) ? round((float)$cpu_items[0]['lastvalue'], 2) . '%' : 'N/A';

			$mem_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'vm.memory.utilization'], 'limit' => 1]);
			$mem_util = !empty($mem_items) ? round((float)$mem_items[0]['lastvalue'], 2) . '%' : 'N/A';

			$uptime_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'system.uptime'], 'limit' => 1]);
			if (!empty($uptime_items)) {
				$uptime_sec = (int)$uptime_items[0]['lastvalue'];
				$uptime = floor($uptime_sec / 86400) . 'd ' . floor(($uptime_sec % 86400) / 3600) . 'h';
			} else {
				$uptime = 'N/A';
			}

			$problems = API::Problem()->get(['output' => ['clock', 'r_clock'], 'hostids' => [$hostid], 'time_from' => $time_from, 'time_till' => $time_to, 'recent' => false]);
			$downtime_seconds = 0;
			foreach ($problems as $p) {
				$p_start = max((int)$p['clock'], $time_from);
				$p_end   = ($p['r_clock'] > 0) ? min((int)$p['r_clock'], $time_to) : $time_to;
				if ($p_end > $p_start) $downtime_seconds += ($p_end - $p_start);
			}
			$sla = ($total_seconds > 0) ? round((($total_seconds - $downtime_seconds) / $total_seconds) * 100, 3) : 100;
			$status = ($host['status'] == HOST_STATUS_MONITORED) ? 'Up' : 'Down';

			$rows[] = [
				'Host Name'     => $host['name'],
				'Hostname'      => $host['host'],
				'IP Address'    => $ip,
				'Group'         => $group_name,
				'OS'            => $host['inventory']['os'] ?? 'N/A',
				'Type'          => $host['inventory']['type'] ?? 'N/A',
				'Status'        => $status,
				'CPU Util'      => $cpu_util,
				'Memory Util'   => $mem_util,
				'Uptime'        => $uptime,
				'SLA %'         => $sla . '%',
				'Problems'      => count($problems),
				'Downtime'      => gmdate('H:i:s', $downtime_seconds)
			];
		}

		if ($format === 'csv') {
			$this->exportCsv($rows, $from, $to);
		} else {
			$this->exportPdf($rows, $from, $to);
		}
	}

	private function exportCsv(array $rows, string $from, string $to): void {
		$filename = 'zabbix_report_' . $from . '_to_' . $to . '.csv';
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		$output = fopen('php://output', 'w');
		if (!empty($rows)) {
			fputcsv($output, array_keys($rows[0]));
			foreach ($rows as $row) {
				fputcsv($output, array_values($row));
			}
		}
		fclose($output);
		exit;
	}

	private function exportPdf(array $rows, string $from, string $to): void {
		$filename = 'zabbix_report_' . $from . '_to_' . $to . '.pdf';
		header('Content-Type: text/html; charset=utf-8');
		header('Content-Disposition: inline; filename="' . $filename . '"');

		$html = '<!DOCTYPE html><html><head><meta charset="UTF-8">
		<title>Device & SLA Report</title>
		<style>
			* { box-sizing: border-box; margin: 0; padding: 0; }
			body { font-family: Arial, sans-serif; font-size: 11px; color: #222; padding: 20px; }
			h1 { font-size: 18px; margin-bottom: 4px; color: #1a1a2e; }
			.subtitle { font-size: 12px; color: #555; margin-bottom: 16px; }
			table { width: 100%; border-collapse: collapse; }
			th { background: #1a1a2e; color: #fff; padding: 7px 6px; text-align: left; font-size: 10px; }
			td { padding: 6px; border-bottom: 1px solid #e0e0e0; vertical-align: middle; }
			tr:nth-child(even) td { background: #f5f7fa; }
			.status-up { color: #27ae60; font-weight: bold; }
			.status-down { color: #e74c3c; font-weight: bold; }
			.sla-good { color: #27ae60; font-weight: bold; }
			.sla-warn { color: #f39c12; font-weight: bold; }
			.sla-bad  { color: #e74c3c; font-weight: bold; }
			.summary { display: flex; gap: 20px; margin-bottom: 16px; }
			.summary-box { background: #f0f4ff; border-left: 4px solid #1a1a2e; padding: 10px 16px; border-radius: 4px; }
			.summary-box .val { font-size: 22px; font-weight: bold; color: #1a1a2e; }
			.summary-box .lbl { font-size: 10px; color: #666; }
			@media print {
				body { padding: 10px; }
				.no-print { display: none; }
			}
		</style></head><body>';

		$total_hosts = count($rows);
		$up_hosts    = count(array_filter($rows, fn($r) => $r['Status'] === 'Up'));
		$avg_sla     = $total_hosts > 0 ? round(array_sum(array_column($rows, 'SLA %')) / $total_hosts, 2) : 0;
		// avg_sla column has '%' suffix, recalculate cleanly
		$sla_vals = array_map(fn($r) => (float)rtrim($r['SLA %'], '%'), $rows);
		$avg_sla  = $total_hosts > 0 ? round(array_sum($sla_vals) / $total_hosts, 3) : 0;
		$total_problems = array_sum(array_column($rows, 'Problems'));

		$html .= '<h1>Device &amp; SLA Report</h1>';
		$html .= '<div class="subtitle">Period: ' . htmlspecialchars($from) . ' to ' . htmlspecialchars($to) . ' &nbsp;|&nbsp; Generated: ' . date('Y-m-d H:i:s') . '</div>';
		$html .= '<div class="summary">';
		$html .= '<div class="summary-box"><div class="val">' . $total_hosts . '</div><div class="lbl">Total Devices</div></div>';
		$html .= '<div class="summary-box"><div class="val">' . $up_hosts . '</div><div class="lbl">Devices Up</div></div>';
		$html .= '<div class="summary-box"><div class="val">' . ($total_hosts - $up_hosts) . '</div><div class="lbl">Devices Down</div></div>';
		$html .= '<div class="summary-box"><div class="val">' . $avg_sla . '%</div><div class="lbl">Avg SLA</div></div>';
		$html .= '<div class="summary-box"><div class="val">' . $total_problems . '</div><div class="lbl">Total Problems</div></div>';
		$html .= '</div>';

		$html .= '<table><thead><tr>
			<th>#</th><th>Host Name</th><th>IP Address</th><th>Group</th>
			<th>OS</th><th>Status</th><th>CPU</th><th>Memory</th>
			<th>Uptime</th><th>SLA %</th><th>Problems</th><th>Downtime</th>
		</tr></thead><tbody>';

		$i = 1;
		foreach ($rows as $row) {
			$status_class = ($row['Status'] === 'Up') ? 'status-up' : 'status-down';
			$sla_val = (float)rtrim($row['SLA %'], '%');
			$sla_class = ($sla_val >= 99.9) ? 'sla-good' : (($sla_val >= 99) ? 'sla-warn' : 'sla-bad');

			$html .= '<tr>';
			$html .= '<td>' . $i++ . '</td>';
			$html .= '<td><strong>' . htmlspecialchars($row['Host Name']) . '</strong></td>';
			$html .= '<td>' . htmlspecialchars($row['IP Address']) . '</td>';
			$html .= '<td>' . htmlspecialchars($row['Group']) . '</td>';
			$html .= '<td>' . htmlspecialchars($row['OS']) . '</td>';
			$html .= '<td class="' . $status_class . '">' . $row['Status'] . '</td>';
			$html .= '<td>' . htmlspecialchars($row['CPU Util']) . '</td>';
			$html .= '<td>' . htmlspecialchars($row['Memory Util']) . '</td>';
			$html .= '<td>' . htmlspecialchars($row['Uptime']) . '</td>';
			$html .= '<td class="' . $sla_class . '">' . htmlspecialchars($row['SLA %']) . '</td>';
			$html .= '<td>' . (int)$row['Problems'] . '</td>';
			$html .= '<td>' . htmlspecialchars($row['Downtime']) . '</td>';
			$html .= '</tr>';
		}

		$html .= '</tbody></table>';
		$html .= '<br><p style="color:#999;font-size:10px;">Generated by Zabbix Custom Reports Module</p>';
		$html .= '<script>window.onload = function(){ window.print(); }</script>';
		$html .= '</body></html>';

		echo $html;
		exit;
	}
}
