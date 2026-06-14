<?php

class CControllerCustomReportsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'from'    => 'string',
			'to'      => 'string',
			'groupid' => 'id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->getUserType() >= USER_TYPE_ZABBIX_USER;
	}

	protected function doAction(): void {
		$from    = $this->getInput('from', date('Y-m-d', strtotime('-30 days')));
		$to      = $this->getInput('to', date('Y-m-d'));
		$groupid = $this->getInput('groupid', 0);

		$groups = API::HostGroup()->get([
			'output'    => ['groupid', 'name'],
			'sortfield' => 'name'
		]);

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

		$hosts         = API::Host()->get($host_filter);
		$time_from     = strtotime($from . ' 00:00:00');
		$time_to       = strtotime($to . ' 23:59:59');
		$total_seconds = $time_to - $time_from;
		$host_data     = [];

		foreach ($hosts as $host) {
			$hostid     = $host['hostid'];
			$ip         = !empty($host['interfaces']) ? $host['interfaces'][0]['ip'] : 'N/A';
			$group_name = !empty($host['groups']) ? $host['groups'][0]['name'] : 'N/A';

			// CPU
			$cpu_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'system.cpu.util'], 'limit' => 1]);
			$cpu_util  = !empty($cpu_items) ? round((float)$cpu_items[0]['lastvalue'], 2) . '%' : 'N/A';

			// Memory
			$mem_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'vm.memory.utilization'], 'limit' => 1]);
			$mem_util  = !empty($mem_items) ? round((float)$mem_items[0]['lastvalue'], 2) . '%' : 'N/A';

			// Uptime
			$up_items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'system.uptime'], 'limit' => 1]);
			if (!empty($up_items)) {
				$s      = (int)$up_items[0]['lastvalue'];
				$uptime = floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
			} else {
				$uptime = 'N/A';
			}

			// SLA
			$problems         = API::Problem()->get(['output' => ['clock', 'r_clock'], 'hostids' => [$hostid], 'time_from' => $time_from, 'time_till' => $time_to, 'recent' => false]);
			$downtime_seconds = 0;
			foreach ($problems as $p) {
				$ps = max((int)$p['clock'], $time_from);
				$pe = ($p['r_clock'] > 0) ? min((int)$p['r_clock'], $time_to) : $time_to;
				if ($pe > $ps) $downtime_seconds += ($pe - $ps);
			}
			$sla    = ($total_seconds > 0) ? round((($total_seconds - $downtime_seconds) / $total_seconds) * 100, 3) : 100;
			$status = ($host['status'] == HOST_STATUS_MONITORED) ? 'Up' : 'Down';

			$host_data[] = [
				'name'     => $host['name'],
				'host'     => $host['host'],
				'ip'       => $ip,
				'group'    => $group_name,
				'os'       => $host['inventory']['os'] ?? 'N/A',
				'type'     => $host['inventory']['type'] ?? 'N/A',
				'status'   => $status,
				'cpu_util' => $cpu_util,
				'mem_util' => $mem_util,
				'uptime'   => $uptime,
				'sla'      => $sla . '%',
				'sla_raw'  => $sla,
				'problems' => count($problems),
				'downtime' => gmdate('H:i:s', $downtime_seconds)
			];
		}

		$this->setResponse(new CControllerResponseData([
			'host_data' => $host_data,
			'groups'    => $groups,
			'from'      => $from,
			'to'        => $to,
			'groupid'   => $groupid,
			'title'     => 'Device & SLA Report'
		]));
	}
}
