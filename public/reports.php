<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';

use ReliefHub\Backend\Env;
use ReliefHub\Backend\Database;

try {
	$env = Env::load(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');
	$pdo = Database::connect($env);
} catch (Throwable $e) {
	http_response_code(500);
	echo '<!DOCTYPE html><meta charset="utf-8"><title>Relief Hub</title><pre>Failed to initialize</pre>';
	exit;
}

// Aggregations
$totals = [
	'count' => 0,
	'by_status' => ['active' => 0, 'withdrawn' => 0, 'resolved' => 0],
	'by_urgency' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
];

// total count
$totals['count'] = (int)$pdo->query("SELECT COUNT(*) FROM v_public_help_requests")->fetchColumn();

// by status
$stStmt = $pdo->query("SELECT status, COUNT(*) c FROM v_public_help_requests GROUP BY status");
foreach ($stStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$k = strtolower((string)$row['status']);
	if (!isset($totals['by_status'][$k])) { $totals['by_status'][$k] = 0; }
	$totals['by_status'][$k] = (int)$row['c'];
}

// by urgency
$ugStmt = $pdo->query("SELECT urgency, COUNT(*) c FROM v_public_help_requests GROUP BY urgency");
foreach ($ugStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$k = strtolower((string)$row['urgency']);
	if (!isset($totals['by_urgency'][$k])) { $totals['by_urgency'][$k] = 0; }
	$totals['by_urgency'][$k] = (int)$row['c'];
}

// last 14 days submissions per day by status
$days = [];
$series = [ 'active' => [], 'withdrawn' => [], 'resolved' => [] ];
for ($i = 13; $i >= 0; $i--) {
	$day = (new DateTimeImmutable("-$i days"))->format('Y-m-d');
	$days[$day] = 0; // keep labels
	$series['active'][$day] = 0;
	$series['withdrawn'][$day] = 0;
	$series['resolved'][$day] = 0;
}
$perDayStmt = $pdo->query("SELECT DATE(created_at) d, LOWER(status) s, COUNT(*) c FROM v_public_help_requests GROUP BY DATE(created_at), status ORDER BY d ASC");
foreach ($perDayStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
	$d = (string)$row['d'];
	$s = (string)$row['s'];
	if (isset($series[$s]) && array_key_exists($d, $series[$s])) { $series[$s][$d] = (int)$row['c']; }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Relief Hub - Reports</title>
	<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet" />
	<style>
		:root { --bg:#f8fafc; --card:#ffffff; --muted:#64748b; --text:#0f172a; --accent:#0ea5e9; --border:#e5e7eb; }
		* { box-sizing: border-box; }
		body { margin:0; font-family: Manrope, Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }
		.sidebar { position: fixed; top:0; bottom:0; left:0; width: 220px; background: #ffffff; border-right: 1px solid var(--border); padding: 18px 14px; transition: transform .25s ease; z-index: 950; }
		.sidebar .brand { font-weight: 800; letter-spacing: .4px; }
		.sidebar nav { margin-top: 18px; display:flex; flex-direction:column; gap:6px; }
		.sidebar a { display:block; color: var(--text); text-decoration: none; padding:10px 12px; border-radius: 8px; }
		.sidebar a:hover { background: #f1f5f9; }
		.sidebar a.active { background: var(--accent); color:#ffffff; }
		.content { margin-left: 220px; transition: margin-left .25s ease; }
		header { padding: 16px 20px; border-bottom: 1px solid var(--border); display:flex; align-items:center; justify-content:space-between; position: sticky; top:0; background: #ffffffcc; backdrop-filter: blur(6px); z-index: 1000; }
		header h1 { margin:0; font-size: 18px; letter-spacing:0.2px; text-transform: uppercase; }
		#menu-btn { display:none; background:#ffffff; border:1px solid var(--border); border-radius:8px; padding:8px 10px; cursor:pointer; }
		main { padding: 20px; display:grid; grid-template-columns: 1fr; gap:16px; }
		.cards { display:grid; grid-template-columns: repeat(2, 1fr); gap:12px; }
		@media (min-width: 900px) { .cards { grid-template-columns: repeat(4, 1fr); } }
		.card { background: var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow: 0 6px 18px rgba(2,6,23,0.05); }
		.kpi { display:flex; flex-direction:column; gap:6px; }
		.kpi .label { color: var(--muted); font-size:12px; }
		.kpi .value { font-size:22px; font-weight:800; }
		.grid { display:grid; grid-template-columns: 1fr; gap:16px; }
		@media (min-width: 1100px) { .grid { grid-template-columns: 1.2fr 1fr; } }
		table { width:100%; border-collapse: collapse; font-size:14px; }
		thead th { text-align:left; color: var(--muted); font-weight:700; padding:10px 12px; border-bottom:1px solid var(--border); }
		tbody td { padding:12px; border-bottom:1px solid var(--border); }
		.badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
		.status-active { background:#dcfce7; color:#065f46; border:1px solid #bbf7d0; }
		.status-withdrawn { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
		.status-resolved { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }
		/* Mobile sidebar behavior */
		@media (max-width: 900px) {
			.sidebar { transform: translateX(-100%); top:60px; height: calc(100% - 60px); }
			.sidebar.open { transform: translateX(0); box-shadow: 0 6px 24px rgba(2,6,23,0.2); }
			.content { margin-left: 0; }
			#menu-btn { display:inline-block; }
		}
	</style>
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
	<aside class="sidebar">
		<div class="brand">Relief Hub</div>
		<nav>
			<a href="./dashboard.php">Dashboard</a>
			<a href="./reports.php" class="active" aria-current="page">Reports</a>
			<a href="./users.php">Users</a>
			<a href="./settings.php">Settings</a>
		</nav>
	</aside>
	<div class="content">
	<header>
		<h1>Reports</h1>
		<button id="menu-btn" aria-label="Toggle menu">☰</button>
	</header>
	<main>
		<section class="cards">
			<div class="card kpi"><div class="label">Total Requests</div><div class="value"><?= (int)$totals['count'] ?></div></div>
			<div class="card kpi"><div class="label">Active</div><div class="value"><?= (int)$totals['by_status']['active'] ?></div></div>
			<div class="card kpi"><div class="label">Withdrawn</div><div class="value"><?= (int)$totals['by_status']['withdrawn'] ?></div></div>
			<div class="card kpi"><div class="label">Resolved</div><div class="value"><?= (int)$totals['by_status']['resolved'] ?></div></div>
		</section>

		<section class="grid">
			<div class="card">
				<h3 style="margin:0 0 12px 0; font-size:16px;">Submissions by Status (last 14 days)</h3>
				<canvas id="chartDaily" height="140"></canvas>
			</div>
			<div class="card">
				<canvas id="chartUrgency" height="160" style="margin-top:12px;"></canvas>
			</div>
		</section>
	</main>
	<script>
		// Mobile sidebar toggle
		(function(){
			const menuBtn = document.getElementById('menu-btn');
			const sidebar = document.querySelector('.sidebar');
			menuBtn?.addEventListener('click', function(){
				sidebar?.classList.toggle('open');
			});
		})();

		// Charts
		const dailyLabels = <?= json_encode(array_keys($days)) ?>;
		const activeValues = <?= json_encode(array_values($series['active'])) ?>;
		const withdrawnValues = <?= json_encode(array_values($series['withdrawn'])) ?>;
		const resolvedValues = <?= json_encode(array_values($series['resolved'])) ?>;
		new Chart(document.getElementById('chartDaily'), {
			type: 'line',
			data: {
				labels: dailyLabels,
				datasets: [
					{ label: 'Active', data: activeValues, borderColor: '#10b981', backgroundColor: '#10b981', tension: .25, fill:false },
					{ label: 'Withdrawn', data: withdrawnValues, borderColor: '#ef4444', backgroundColor: '#ef4444', tension: .25, fill:false },
					{ label: 'Resolved', data: resolvedValues, borderColor: '#6366f1', backgroundColor: '#6366f1', tension: .25, fill:false }
				]
			},
			options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: true } } }
		});

		// Urgency bar chart
		const urgencyLabels = ['critical','high','medium','low'];
		const urgencyValues = [
			<?= (int)$totals['by_urgency']['critical'] ?>,
			<?= (int)$totals['by_urgency']['high'] ?>,
			<?= (int)$totals['by_urgency']['medium'] ?>,
			<?= (int)$totals['by_urgency']['low'] ?>,
		];
		new Chart(document.getElementById('chartUrgency'), {
			type: 'bar',
			data: {
				labels: urgencyLabels,
				datasets: [{
					label: 'Requests',
					data: urgencyValues,
					backgroundColor: ['#ef4444','#f59e0b','#22d3ee','#10b981'],
					borderColor: ['#ef4444','#f59e0b','#22d3ee','#10b981']
				}]
			},
			options: { scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
		});
	</script>
	</div>
</body>
</html>


