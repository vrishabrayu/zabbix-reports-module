<?php
/**
 * @var CView $this
 * @var array $data
 */

$host_data = $data['host_data'];
$groups    = $data['groups'];
$from      = $data['from'];
$to        = $data['to'];
$groupid   = $data['groupid'];

$total_hosts    = count($host_data);
$up_hosts       = count(array_filter($host_data, fn($h) => $h['status'] === 'Up'));
$down_hosts     = $total_hosts - $up_hosts;
$sla_vals       = array_map(fn($h) => $h['sla_raw'], $host_data);
$avg_sla        = $total_hosts > 0 ? round(array_sum($sla_vals) / $total_hosts, 3) : 100;
$total_problems = array_sum(array_column($host_data, 'problems'));
?>

<style>
.cr-wrap { padding: 20px; font-family: Arial, sans-serif; }
.cr-title { font-size: 22px; font-weight: bold; color: #1a1a2e; margin-bottom: 16px; }
.cr-filter { background: #f5f7fa; border: 1px solid #dde3ee; border-radius: 6px; padding: 16px 20px; margin-bottom: 20px; display: flex; gap: 16px; align-items: flex-end; flex-wrap: wrap; }
.cr-filter label { font-size: 12px; font-weight: bold; color: #444; display: block; margin-bottom: 4px; }
.cr-filter input, .cr-filter select { border: 1px solid #c8d0e0; border-radius: 4px; padding: 6px 10px; font-size: 13px; height: 32px; }
.cr-filter button { background: #1a1a2e; color: white; border: none; border-radius: 4px; padding: 7px 18px; cursor: pointer; font-size: 13px; height: 32px; }
.cr-filter button:hover { background: #2e3a6e; }
.cr-summary { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
.cr-card { background: white; border: 1px solid #dde3ee; border-radius: 8px; padding: 14px 20px; min-width: 130px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.cr-card .val { font-size: 26px; font-weight: bold; color: #1a1a2e; }
.cr-card .lbl { font-size: 11px; color: #777; margin-top: 2px; }
.cr-card.green .val { color: #27ae60; }
.cr-card.red .val   { color: #e74c3c; }
.cr-card.blue .val  { color: #2980b9; }
.cr-card.orange .val { color: #e67e22; }
.cr-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.cr-toolbar .search-wrap input { border: 1px solid #c8d0e0; border-radius: 4px; padding: 6px 12px; font-size: 13px; width: 220px; }
.cr-export-btns { display: flex; gap: 8px; }
.cr-btn { padding: 7px 16px; border-radius: 4px; font-size: 13px; cursor: pointer; border: none; font-weight: bold; }
.cr-btn-csv  { background: #27ae60; color: white; }
.cr-btn-pdf  { background: #e74c3c; color: white; }
.cr-btn-csv:hover { background: #219a52; }
.cr-btn-pdf:hover { background: #c0392b; }
table.cr-table { width: 100%; border-collapse: collapse; font-size: 12px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
table.cr-table thead tr { background: #1a1a2e; color: white; }
table.cr-table th { padding: 10px 10px; text-align: left; font-size: 11px; font-weight: bold; white-space: nowrap; }
table.cr-table td { padding: 9px 10px; border-bottom: 1px solid #eef0f5; vertical-align: middle; }
table.cr-table tbody tr:hover td { background: #f0f4ff; }
table.cr-table tbody tr:nth-child(even) td { background: #f8f9fc; }
table.cr-table tbody tr:nth-child(even):hover td { background: #f0f4ff; }
.badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
.badge-up   { background: #d5f5e3; color: #1e8449; }
.badge-down { background: #fadbd8; color: #922b21; }
.sla-good { color: #27ae60; font-weight: bold; }
.sla-warn { color: #e67e22; font-weight: bold; }
.sla-bad  { color: #e74c3c; font-weight: bold; }
.cr-pagination { display: flex; gap: 6px; align-items: center; margin-top: 12px; justify-content: flex-end; font-size: 13px; }
.cr-pagination button { border: 1px solid #c8d0e0; background: white; border-radius: 4px; padding: 4px 10px; cursor: pointer; }
.cr-pagination button.active { background: #1a1a2e; color: white; border-color: #1a1a2e; }
.cr-pagination button:hover:not(.active) { background: #f0f4ff; }
</style>

<div class="cr-wrap">
	<div class="cr-title">📊 Device &amp; SLA Report</div>

	<!-- Filter Bar -->
	<form method="GET" action="<?= (new CUrl('zabbix.php'))->setArgument('action', 'customreports.view') ?>">
		<div class="cr-filter">
			<div>
				<label>From Date</label>
				<input type="date" name="from" value="<?= htmlspecialchars($from) ?>">
			</div>
			<div>
				<label>To Date</label>
				<input type="date" name="to" value="<?= htmlspecialchars($to) ?>">
			</div>
			<div>
				<label>Host Group</label>
				<select name="groupid">
					<option value="0">-- All Groups --</option>
					<?php foreach ($groups as $g): ?>
						<option value="<?= $g['groupid'] ?>" <?= ($groupid == $g['groupid']) ? 'selected' : '' ?>>
							<?= htmlspecialchars($g['name']) ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<div>
				<button type="submit" name="filter_set" value="1">🔍 Apply Filter</button>
			</div>
		</div>
	</form>

	<!-- Summary Cards -->
	<div class="cr-summary">
		<div class="cr-card"><div class="val"><?= $total_hosts ?></div><div class="lbl">Total Devices</div></div>
		<div class="cr-card green"><div class="val"><?= $up_hosts ?></div><div class="lbl">Devices Up</div></div>
		<div class="cr-card red"><div class="val"><?= $down_hosts ?></div><div class="lbl">Devices Down</div></div>
		<div class="cr-card blue"><div class="val"><?= $avg_sla ?>%</div><div class="lbl">Avg SLA</div></div>
		<div class="cr-card orange"><div class="val"><?= $total_problems ?></div><div class="lbl">Total Problems</div></div>
	</div>

	<!-- Toolbar -->
	<div class="cr-toolbar">
		<div class="search-wrap">
			<input type="text" id="cr-search" placeholder="🔍 Search host, IP, group..." onkeyup="filterTable()">
		</div>
		<div class="cr-export-btns">
			<a href="<?= (new CUrl('zabbix.php'))
				->setArgument('action', 'customreports.export')
				->setArgument('format', 'csv')
				->setArgument('from', $from)
				->setArgument('to', $to)
				->setArgument('groupid', $groupid) ?>">
				<button class="cr-btn cr-btn-csv">⬇ Export CSV</button>
			</a>
			<a href="<?= (new CUrl('zabbix.php'))
				->setArgument('action', 'customreports.export')
				->setArgument('format', 'pdf')
				->setArgument('from', $from)
				->setArgument('to', $to)
				->setArgument('groupid', $groupid) ?>" target="_blank">
				<button class="cr-btn cr-btn-pdf">⬇ Export PDF</button>
			</a>
		</div>
	</div>

	<!-- Data Table -->
	<table class="cr-table" id="cr-table">
		<thead>
			<tr>
				<th>#</th>
				<th onclick="sortTable(1)" style="cursor:pointer">Host Name ↕</th>
				<th onclick="sortTable(2)" style="cursor:pointer">IP Address ↕</th>
				<th onclick="sortTable(3)" style="cursor:pointer">Group ↕</th>
				<th>OS</th>
				<th>Type</th>
				<th onclick="sortTable(6)" style="cursor:pointer">Status ↕</th>
				<th onclick="sortTable(7)" style="cursor:pointer">CPU Util ↕</th>
				<th onclick="sortTable(8)" style="cursor:pointer">Mem Util ↕</th>
				<th>Uptime</th>
				<th onclick="sortTable(10)" style="cursor:pointer">SLA % ↕</th>
				<th onclick="sortTable(11)" style="cursor:pointer">Problems ↕</th>
				<th>Downtime</th>
			</tr>
		</thead>
		<tbody id="cr-tbody">
		<?php $i = 1; foreach ($host_data as $h): ?>
			<?php
				$sla_class = ($h['sla_raw'] >= 99.9) ? 'sla-good' : (($h['sla_raw'] >= 99) ? 'sla-warn' : 'sla-bad');
				$badge     = ($h['status'] === 'Up') ? 'badge-up' : 'badge-down';
			?>
			<tr>
				<td><?= $i++ ?></td>
				<td><strong><?= htmlspecialchars($h['name']) ?></strong></td>
				<td><?= htmlspecialchars($h['ip']) ?></td>
				<td><?= htmlspecialchars($h['group']) ?></td>
				<td><?= htmlspecialchars($h['os']) ?></td>
				<td><?= htmlspecialchars($h['type']) ?></td>
				<td><span class="badge <?= $badge ?>"><?= $h['status'] ?></span></td>
				<td><?= htmlspecialchars($h['cpu_util']) ?></td>
				<td><?= htmlspecialchars($h['mem_util']) ?></td>
				<td><?= htmlspecialchars($h['uptime']) ?></td>
				<td class="<?= $sla_class ?>"><?= htmlspecialchars($h['sla']) ?></td>
				<td><?= (int)$h['problems'] ?></td>
				<td><?= htmlspecialchars($h['downtime']) ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div class="cr-pagination" id="cr-pagination"></div>
</div>

<script>
// Live search
function filterTable() {
	const q = document.getElementById('cr-search').value.toLowerCase();
	const rows = document.querySelectorAll('#cr-tbody tr');
	rows.forEach(r => {
		r.style.display = r.innerText.toLowerCase().includes(q) ? '' : 'none';
	});
	reindex();
}

// Column sort
let sortDir = {};
function sortTable(col) {
	const tbody = document.getElementById('cr-tbody');
	const rows  = Array.from(tbody.querySelectorAll('tr'));
	sortDir[col] = !sortDir[col];
	rows.sort((a, b) => {
		let av = a.cells[col]?.innerText.trim() || '';
		let bv = b.cells[col]?.innerText.trim() || '';
		const an = parseFloat(av), bn = parseFloat(bv);
		if (!isNaN(an) && !isNaN(bn)) return sortDir[col] ? an - bn : bn - an;
		return sortDir[col] ? av.localeCompare(bv) : bv.localeCompare(av);
	});
	rows.forEach(r => tbody.appendChild(r));
	reindex();
}

// Re-number visible rows
function reindex() {
	let n = 1;
	document.querySelectorAll('#cr-tbody tr').forEach(r => {
		if (r.style.display !== 'none') r.cells[0].innerText = n++;
	});
}
</script>
