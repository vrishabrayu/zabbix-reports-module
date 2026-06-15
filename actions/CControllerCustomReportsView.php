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
			'output'             => ['hostid', 'host', 'name', 'status'],
			'selectInterfaces'   => ['ip', 'type'],
			'selectHostGroups'   => ['groupid', 'name'],
			'selectInventory'    => ['os', 'type', 'os_full', 'hardware', 'hardware_full'],
			'monitored_hosts'    => true,
			'sortfield'          => 'name'
		];
		if ($groupid) {
			$host_filter['groupids'] = [$groupid];
		}

		$hosts         = API::Host()->get($host_filter);
		$time_from     = strtotime($from . ' 00:00:00');
		$time_to       = strtotime($to . ' 23:59:59');
		$total_seconds = max(1, $time_to - $time_from);
		$host_data     = [];

		foreach ($hosts as $host) {
			$hostid = $host['hostid'];

			// IP — get first non-loopback interface
			$ip = 'N/A';
			if (!empty($host['interfaces'])) {
				foreach ($host['interfaces'] as $iface) {
					if ($iface['ip'] !== '127.0.0.1') {
						$ip = $iface['ip'];
						break;
					}
				}
				if ($ip === 'N/A') $ip = $host['interfaces'][0]['ip'];
			}

			// Group — try both selectHostGroups and selectGroups keys
			$group_name = 'N/A';
			if (!empty($host['hostgroups'])) {
				$group_name = $host['hostgroups'][0]['name'];
			} elseif (!empty($host['groups'])) {
				$group_name = $host['groups'][0]['name'];
			}

			// OS — from inventory or fallback to system.uname item
			$os = 'N/A';
			if (!empty($host['inventory'])) {
				$inv = $host['inventory'];
				if (!empty($inv['os'])) $os = $inv['os'];
				elseif (!empty($inv['os_full'])) $os = $inv['os_full'];
			}
			if ($os === 'N/A') {
				$uname = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => 'system.uname'], 'limit' => 1]);
				if (!empty($uname)) {
					$parts = explode(' ', $uname[0]['lastvalue']);
					$os = isset($parts[0]) ? $parts[0] : $uname[0]['lastvalue'];
				}
			}

			// Type — from inventory
			$type = 'N/A';
			if (!empty($host['inventory'])) {
				$inv = $host['inventory'];
				if (!empty($inv['type'])) $type = $inv['type'];
				elseif (!empty($inv['hardware'])) $type = $inv['hardware'];
			}

			// CPU — try multiple keys
			$cpu_util = 'N/A';
			$cpu_keys = ['system.cpu.util', 'system.cpu.util[,idle]', 'system.cpu.load'];
			foreach ($cpu_keys as $key) {
				$items = API::Item()->get(['output' => ['lastvalue', 'key_'], 'hostids' => [$hostid], 'search' => ['key_' => $key], 'startSearch' => true, 'limit' => 1]);
				if (!empty($items) && $items[0]['lastvalue'] !== '') {
					$cpu_util = round((float)$items[0]['lastvalue'], 1) . '%';
					break;
				}
			}

			// Memory — try multiple keys
			$mem_util = 'N/A';
			$mem_keys = ['vm.memory.utilization', 'vm.memory.size[pavailable]', 'vm.memory.size[available]'];
			foreach ($mem_keys as $key) {
				$items = API::Item()->get(['output' => ['lastvalue', 'key_'], 'hostids' => [$hostid], 'search' => ['key_' => $key], 'startSearch' => true, 'limit' => 1]);
				if (!empty($items) && $items[0]['lastvalue'] !== '') {
					$val = round((float)$items[0]['lastvalue'], 1);
					// if key is 'available', convert to used %
					if (strpos($key, 'available') !== false && $val <= 100) {
						$val = round(100 - $val, 1);
					}
					$mem_util = $val . '%';
					break;
				}
			}

			// Uptime — try multiple keys
			$uptime = 'N/A';
			$up_keys = ['system.uptime', 'system.uptime[0]'];
			foreach ($up_keys as $key) {
				$items = API::Item()->get(['output' => ['lastvalue'], 'hostids' => [$hostid], 'search' => ['key_' => $key], 'startSearch' => true, 'limit' => 1]);
				if (!empty($items) && $items[0]['lastvalue'] !== '' && (int)$items[0]['lastvalue'] > 0) {
					$s      = (int)$items[0]['lastvalue'];
					$uptime = floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
					break;
				}
			}

			// SLA — calculate from events (not problems) to avoid overlap issues
			$events = API::Event()->get([
				'output'       => ['clock', 'value'],
				'objectids'    => array_column(
					API::Trigger()->get(['output' => ['triggerid'], 'hostids' => [$hostid], 'only_true' => false]),
					'triggerid'
				),
				'source'       => EVENT_SOURCE_TRIGGERS,
				'object'       => EVENT_OBJECT_TRIGGER,
				'time_from'    => $time_from,
				'time_till'    => $time_to,
				'sortfield'    => 'clock',
				'sortorder'    => 'ASC',
				'limit'        => 1000
			]);

			// Simpler SLA: use problem count * avg duration estimation
			// More reliable: use availability API
			$problems = API::Problem()->get([
				'output'    => ['clock', 'r_clock'],
				'hostids'   => [$hostid],
				'time_from' => $time_from,
				'time_till' => $time_to,
				'recent'    => false,
				'limit'     => 500
			]);

			$downtime_seconds = 0;
			$intervals = [];

			// Merge overlapping intervals to avoid negative SLA
			foreach ($problems as $p) {
				$ps = max((int)$p['clock'], $time_from);
				$pe = ((int)$p['r_clock'] > 0) ? min((int)$p['r_clock'], $time_to) : $time_to;
				if ($pe > $ps) {
					$intervals[] = [$ps, $pe];
				}
			}

			// Sort and merge overlapping intervals
			if (!empty($intervals)) {
				usort($intervals, fn($a, $b) => $a[0] - $b[0]);
				$merged = [$intervals[0]];
				for ($i = 1; $i < count($intervals); $i++) {
					$last = &$merged[count($merged) - 1];
					if ($intervals[$i][0] <= $last[1]) {
						$last[1] = max($last[1], $intervals[$i][1]);
					} else {
						$merged[] = $intervals[$i];
					}
				}
				foreach ($merged as $interval) {
					$downtime_seconds += ($interval[1] - $interval[0]);
				}
			}

			// Cap downtime at total period
			$downtime_seconds = min($downtime_seconds, $total_seconds);
			$sla = round((($total_seconds - $downtime_seconds) / $total_seconds) * 100, 3);
			$sla = max(0, min(100, $sla)); // clamp between 0-100
			$status = ($host['status'] == HOST_STATUS_MONITORED) ? 'Up' : 'Down';

			$host_data[] = [
				'name'     => $host['name'],
				'host'     => $host['host'],
				'ip'       => $ip,
				'group'    => $group_name,
				'os'       => $os,
				'type'     => $type,
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
