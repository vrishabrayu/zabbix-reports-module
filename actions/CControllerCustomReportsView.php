<?php
// v2.1 - removed Type/MemUtil columns, added SNMP uptime support

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
			'selectInventory'  => ['os', 'os_full'],
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

			// OS
			$os  = 'N/A';
			$inv = $host['inventory'] ?? [];
			if (!empty($inv['os'])) $os = $inv['os'];
			elseif (!empty($inv['os_full'])) $os = $inv['os_full'];
			else {
				$val = $this->getItemValue($hostid, ['system.uname', 'system.sw.os', 'system.descr']);
				if ($val !== '') {
					$parts = explode(' ', $val);
					$os    = $parts[0] . (isset($parts[2]) ? ' ' . $parts[2] : '');
				}
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

			// Uptime — agent and SNMP keys
			$uptime = 'N/A';
			$val = $this->getItemValue($hostid, [
				'system.uptime',          // Zabbix agent (Linux/Windows)
				'sysUpTime',              // SNMP generic
				'sysUpTime.0',            // SNMP OID
				'DISMAN-EVENT-MIB::sysUpTimeInstance' // full OID
			]);
			if ($val !== '' && (int)$val > 0) {
				$s = (int)$val;
				// SNMP uptime is in centiseconds (100ths of a second)
				// Zabbix agent uptime is in seconds
				// Detect: if value > 1e9 it's likely centiseconds
				if ($s > 1000000000) $s = (int)($s / 100);
				$uptime = floor($s / 86400) . 'd ' . floor(($s % 86400) / 3600) . 'h';
			}

			// SLA
			$problems = API::Problem()->get([
				'output'    => ['clock', 'r_clock'],
				'hostids'   => [$hostid],
				'time_from' => $time_from,
				'time_till' => $time_to,
				'recent'    => false,
				'limit'     => 1000
			]);

			$intervals = [];
			foreach ($problems as $p) {
				$ps = max((int)$p['clock'], $time_from);
				$pe = ((int)$p['r_clock'] > 0) ? min((int)$p['r_clock'], $time_to) : $time_to;
				if ($pe > $ps) $intervals[] = [$ps, $pe];
			}

			$downtime_seconds = 0;
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
				'status'   => $status,
				'cpu_util' => $cpu_util,
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
