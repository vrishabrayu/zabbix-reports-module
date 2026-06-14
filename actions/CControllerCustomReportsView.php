<?php

class CControllerCustomReportsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'from'       => 'string',
			'to'         => 'string',
			'groupid'    => 'id',
			'filter_set' => 'in 1'
		];

		$ret = $this->validateInput($fields);
		return $ret;
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_DEFAULT_ACCESS);
	}

	protected function doAction(): void {
		$from = $this->getInput('from', date('Y-m-d', strtotime('-30 days')));
		$to   = $this->getInput('to', date('Y-m-d'));
		$groupid = $this->getInput('groupid', 0);

		// Get host groups for filter dropdown
		$groups = API::HostGroup()->get([
			'output' => ['groupid', 'name'],
			'sortfield' => 'name'
		]);

		// Build host filter
		$host_filter = [
			'output'           => ['hostid', 'host', 'name', 'status'],
			'selectInterfaces' => ['ip'],
			'selectGroups'     => ['name'],
			'selectInventory'  => ['os', 'type', 'location'],
			'monitored_hosts'  => true,
			'sortfield'        => 'name'
		];
		if ($groupid) {
			$host_filter['groupids'] = [$groupid];
		}

		$hosts = API::Host()->get($host_filter);
		$host_data = [];

		$time_from = strtotime($from . ' 00:00:00');
		$time_to   = strtotime($to . ' 23:59:59');
		$total_seconds = $time_to - $time_from;

		foreach ($hosts as $host) {
			$hostid = $host['hostid'];
			$ip = '';
			if (!empty($host['interfaces'])) {
				$ip = $host['interfaces'][0]['ip'];
			}
			$group_name = !empty($host['groups']) ? $host['groups'][0]['name'] : 'N/A';

			// Get CPU utilization item
			$cpu_items = API::Item()->get([
				'output'  => ['lastvalue', 'units'],
				'hostids' => [$hostid],
				'search'  => ['key_' => 'system.cpu.util'],
				'limit'   => 1
			]);
			$cpu_util = (!empty($cpu_items)) ? round((float)$cpu_items[0]['lastvalue'], 2) . '%' : 'N/A';

			// Get memory utilization item
			$mem_items = API::Item()->get([
				'output'  => ['lastvalue', 'units'],
				'hostids' => [$hostid],
				'search'  => ['key_' => 'vm.memory.utilization'],
				'limit'   => 1
			]);
			if (empty($mem_items)) {
				$mem_items = API::Item()->get([
					'output'  => ['lastvalue'],
					'hostids' => [$hostid],
					'search'  => ['key_' => 'mem.util.used'],
					'limit'   => 1
				]);
			}
			$mem_util = (!empty($mem_items)) ? round((float)$mem_items[0]['lastvalue'], 2) . '%' : 'N/A';

			// Get uptime item
			$uptime_items = API::Item()->get([
				'output'  => ['lastvalue'],
				'hostids' => [$hostid],
				'search'  => ['key_' => 'system.uptime'],
				'limit'   => 1
			]);
			if (!empty($uptime_items)) {
				$uptime_sec = (int)$uptime_items[0]['lastvalue'];
				$days    = floor($uptime_sec / 86400);
				$hours   = floor(($uptime_sec % 86400) / 3600);
				$uptime  = "{$days}d {$hours}h";
			} else {
				$uptime = 'N/A';
			}

			// Calculate SLA (availability) from problems in date range
			$problems = API::Problem()->get([
				'output'     => ['eventid', 'clock', 'r_clock'],
				'hostids'    => [$hostid],
				'time_from'  => $time_from,
				'time_till'  => $time_to,
				'recent'     => false
			]);

			$downtime_seconds = 0;
			foreach ($problems as $problem) {
				$p_start = max((int)$problem['clock'], $time_from);
				$p_end   = ($problem['r_clock'] > 0)
					? min((int)$problem['r_clock'], $time_to)
					: $time_to;
				if ($p_end > $p_start) {
					$downtime_seconds += ($p_end - $p_start);
				}
			}

			$sla = ($total_seconds > 0)
				? round((($total_seconds - $downtime_seconds) / $total_seconds) * 100, 3)
				: 100;

			$status = ($host['status'] == HOST_STATUS_MONITORED) ? 'Up' : 'Down';

			$host_data[] = [
				'name'        => $host['name'],
				'host'        => $host['host'],
				'ip'          => $ip,
				'group'       => $group_name,
				'os'          => $host['inventory']['os'] ?? 'N/A',
				'type'        => $host['inventory']['type'] ?? 'N/A',
				'status'      => $status,
				'cpu_util'    => $cpu_util,
				'mem_util'    => $mem_util,
				'uptime'      => $uptime,
				'sla'         => $sla . '%',
				'sla_raw'     => $sla,
				'problems'    => count($problems),
				'downtime'    => gmdate('H:i:s', $downtime_seconds)
			];
		}

		$this->setResponse(new CControllerResponseData([
			'host_data'  => $host_data,
			'groups'     => $groups,
			'from'       => $from,
			'to'         => $to,
			'groupid'    => $groupid,
			'title'      => 'Device & SLA Report'
		]));
	}
}
