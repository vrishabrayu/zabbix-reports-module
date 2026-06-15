<?php
// v2.0 - fixed SLA calculation, removed Event API sort

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

	private function getItemValue(int $hostid, array $keys): string {
		foreach ($keys as $key) {
			$items = API::Item()->get([
				'output'      => ['lastvalue', 'key_'],
				'hostids'     => [$hostid],
				'search'      => ['key_' => $key],
				'startSearch' => true,
				'filter'      => ['status' => 0],
				'limit'       => 1
			]);
			if (!empty($items) && $items[0]['lastvalue'] !== '') {
				return $items[0]['lastvalue'];
			}
		}
		return '';
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
			'selectInterfaces' => ['ip', 'type'],
			'selectHostGroups' => ['groupid', 'name'],
			'selectInventory'  => ['os', 'os_full', 'type', 'hardware', 'hardware_full'],
			'monitored_hosts'  => true,
			'sortfield'        => 'name'
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

			// IP
			$ip = 'N/A';
			if (!empty($host['interfaces'])) {
				foreach ($host['interfaces'] as $iface) {
					if (!empty($iface['ip']) && $iface['ip'] !== '127.0.0.1') {
						$ip = $iface['ip'];
						break;
					}
				}
				if ($ip === 'N/A') $ip = $host['interfaces'][0]['ip'] ?? 'N/A';
			}

			// Group
			$group_name = 'N/A';
			if (!empty($host['hostgroups'])) {
				$group_name = $host['hostgroups'][0]['name'];
			} elseif (!empty($host['groups'])) {
				$group_name = $host['groups'][0]['name'];
			}

			// OS — inventory first, then system.uname item
			$os = 'N/A';
			$inv = $host['inventory'] ?? [];
			if (!empty($inv['os'])) $os = $inv['os'];
			elseif (!empty($inv['os_full'])) $os = $inv['os_full'];
			else {
				$val = $this->getItemValue($hostid, ['system.uname', 'system.sw.os']);
				if ($val !== '') {
					$parts = explode(' ', $val);
					$os = $parts[0] . (isset($parts[2]) ? ' ' . $parts[2] : '');
				}
			}

			// Type — inventory first, then system description
			$type = 'N/A';
			if (!empty($inv['type'])) $type = $inv['type'];
			elseif (!empty($inv['hardware'])) $type = $inv['hardware'];
			else {
				$val = $this->getItemValue($hostid, ['system.hw.chassis', 'system.description']);
				if ($val !== '') $type = substr($val, 0, 30);
			}

			// CPU
			$cpu_util = 'N/A';
			$val = $this->getItemValue($hostid, [
				'system.cpu.util',
				'system.cpu.util[all,idle]',
				'system.cpu.util[,idle]',
				'system.cpu.load[percpu,avg1]'
			]);
			if ($val !== '') $cpu_util = round((float)$val, 1) . '%';

			// Memory
			$mem_util = 'N/A';
			$val = $this->getItemValue($hostid, ['vm.memory.utilization']);
			if ($val !== '') {
				$mem_util = round((float)$val, 1) . '%';
			} else {
				// Try available memory and calculate used %
				$val = $this->getItemValue($hostid, [
					'vm.memory.size[pavailable]',
					'vm.memory.size[pused]'
				]);
				if ($val !== '') {
					$fval = round((float)$val, 1);
					// if pavailable, invert to get used
					$mem_util = (strpos($this->getItemValue($hostid, ['vm.memory.size[pavailable]']), '') !== false && $fval <= 100)
						? round(100 - $fval, 1) . '%'
						: $fval . '%';
				}
			}

			// Uptime
			$uptime = 'N/A';
			$val = $this->getItemValue($hostid, ['system.uptime']);
			if ($val !== '' && (int)$val > 0) {
				$s      = (int)$val;
				$uptime = floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
			}

			// SLA — fetch problems and merge overlapping intervals
			$problems = API::Problem()->get([
				'output'    => ['clock', 'r_clock'],
				'hostids'   => [$hostid],
				'time_from' => $time_from,
				'time_till' => $time_to,
				'recent'    => false,
				'limit'     => 1000
			]);

			$downtime_seconds = 0;
			$intervals = [];
			foreach ($problems as $p) {
				$ps = max((int)$p['clock'], $time_from);
				$pe = ((int)$p['r_clock'] > 0) ? min((int)$p['r_clock'], $time_to) : $time_to;
				if ($pe > $ps) $intervals[] = [$ps, $pe];
			}

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

			$downtime_seconds = min($downtime_seconds, $total_seconds);
			$sla    = max(0, min(100, round((($total_seconds - $downtime_seconds) / $total_seconds) * 100, 3)));
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
