<?php
declare(strict_types=1);

// Relief Hub - Server-rendered dashboard

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

$urgency = isset($_GET['urgency']) ? (string)$_GET['urgency'] : '';
$bbox = isset($_GET['bbox']) ? (string)$_GET['bbox'] : '';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 25);
if ($perPage < 10) { $perPage = 10; }
if ($perPage > 200) { $perPage = 200; }
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if ($urgency !== '') {
	$where[] = 'urgency = ?';
	$params[] = $urgency;
}

if ($q !== '') {
	$where[] = '(title LIKE ? OR description LIKE ?)';
	$params[] = '%' . $q . '%';
	$params[] = '%' . $q . '%';
}

if ($bbox !== '') {
	$parts = array_map('trim', explode(',', $bbox));
	if (count($parts) === 4) {
		[$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $parts);
		$where[] = 'latitude BETWEEN ? AND ?';
		$where[] = 'longitude BETWEEN ? AND ?';
		$params[] = min($minLat, $maxLat);
		$params[] = max($minLat, $maxLat);
		$params[] = min($minLon, $maxLon);
		$params[] = max($minLon, $maxLon);
	}
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Count for pagination
$countSql = "SELECT COUNT(*) FROM v_public_help_requests $whereSql";
$countStmt = $pdo->prepare($countSql);
foreach ($params as $i => $p) { $countStmt->bindValue($i + 1, $p); }
$countStmt->execute();
$total = (int)$countStmt->fetchColumn();

$sql = "SELECT public_id, title, description, request_type, urgency, people_affected, latitude, longitude, status, created_at
		FROM v_public_help_requests
		$whereSql
		ORDER BY created_at DESC
		LIMIT ? OFFSET ?";

$stmt = $pdo->prepare($sql);
foreach ($params as $i => $p) { $stmt->bindValue($i + 1, $p); }
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Compute KPIs once so we can reuse in full and partial responses
$totalCount = count($rows);
$activeCount = 0; $last24 = 0; $avgPeople = 0.0; $sumPeople = 0; $now = time();
foreach ($rows as $r) {
	if (($r['status'] ?? '') === 'active') { $activeCount++; }
	$ts = strtotime((string)($r['created_at'] ?? '')) ?: 0;
	if ($now - $ts < 24*3600) { $last24++; }
	$sumPeople += (int)($r['people_affected'] ?? 0);
}
$avgPeople = $totalCount ? $sumPeople / $totalCount : 0.0;

// Partial JSON response for AJAX polling
if (isset($_GET['partial']) && $_GET['partial'] === '1') {
	header('Content-Type: application/json; charset=utf-8');
	$outRows = [];
	foreach ($rows as $r) {
		$outRows[] = [
			'public_id' => (string)$r['public_id'],
			'title' => (string)$r['title'],
			'request_type' => (string)$r['request_type'],
			'urgency' => (string)$r['urgency'],
			'people_affected' => (int)$r['people_affected'],
			'latitude' => (float)$r['latitude'],
			'longitude' => (float)$r['longitude'],
			'status' => (string)$r['status'],
			'created_at' => (string)$r['created_at'],
		];
	}
	echo json_encode([
		'kpis' => [
			'total' => $totalCount,
			'active' => $activeCount,
			'last24h' => $last24,
			'avg' => round($avgPeople, 1),
		],
		'rows' => $outRows,
		'pagination' => [
			'page' => $page,
			'per_page' => $perPage,
			'total' => $total,
			'total_pages' => $perPage > 0 ? (int)ceil($total / $perPage) : 0,
		],
	], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Relief Hub - Dashboard</title>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet" />
	<style>
		:root { --bg:#f8fafc; --card:#ffffff; --muted:#64748b; --text:#0f172a; --accent:#0ea5e9; --border:#e5e7eb; }
		* { box-sizing: border-box; }
		body { margin:0; font-family: Poppins, Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }
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
		@media (min-width: 900px) { main { grid-template-columns: 1fr; } }
		.card { background: var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow: 0 6px 18px rgba(2,6,23,0.05);;}
		.kpi-card { background: transparent; border: none; box-shadow: none; padding: 0; }
		.kpis { display:grid; grid-template-columns: 1fr; gap:12px; min-width:0; }
		@media (min-width: 600px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
		@media (min-width: 1000px) { .kpis { grid-template-columns: repeat(4, 1fr); } }
		.kpi { position: relative; display:flex; align-items:center; justify-content:space-between; border-radius:10px; padding:16px; color:#ffffff; overflow:hidden; min-width:0; }
		.kpi .text { display:flex; flex-direction:column; gap:6px; }
		.kpi .label { font-size:11px; letter-spacing:.5px; text-transform:uppercase; opacity:.9; }
		.kpi .value { font-size:24px; font-weight:800; }
		.kpi .icon { font-size:28px; opacity:.85; }
		.kpi-total { background:#e11d48; }
		.kpi-active { background:#38bdf8; }
		.kpi-last { background:#f59e0b; }
		.kpi-avg { background:#8b5cf6; }
		.filters { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; align-items:center; }
		.filters input, .filters select { background:#ffffff; color:var(--text); border:1px solid var(--border); border-radius:10px; padding:10px 12px; font-size:14px; }
		.filters button { background: var(--accent); color:#ffffff; border:none; border-radius:10px; padding:10px 14px; font-weight:700; cursor:pointer; letter-spacing:0.2px; }
		table { width:100%; border-collapse: collapse; font-size:14px; }
		thead th { text-align:left; color: var(--muted); font-weight:700; padding:10px 12px; border-bottom:1px solid var(--border); position:sticky; top:0; background:var(--card); }
		tbody td { padding:12px; border-bottom:1px solid var(--border); vertical-align:top; }
		.badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:11px; font-weight:600; }
		.urgency-critical { background: #fee2e2; color:#991b1b; border:1px solid #fecaca; }
		.urgency-high { background: #fef3c7; color:#92400e; border:1px solid #fde68a; }
		.urgency-medium { background: #cffafe; color:#155e75; border:1px solid #a5f3fc; }
		.urgency-low { background: #dcfce7; color:#065f46; border:1px solid #bbf7d0; }
		.pill { background:#ffffff; border:1px solid var(--border); border-radius:999px; padding:4px 10px; font-size:12px; color:var(--muted); }
		.status-badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; letter-spacing:.3px; text-transform:capitalize; }
		.status-active { background:#dcfce7; color:#065f46; border:1px solid #bbf7d0; }
		.status-withdrawn { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
		.status-resolved { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }
		.scroll { max-height: calc(100vh - 260px); overflow:auto; }
		/* Ensure tables and containers don't exceed viewport width */
		.dataTables_wrapper, .dt-container { max-width: 100%; overflow-x: auto; }
		table { width:100% !important; }
		td { word-break: break-word; }
		thead th { white-space: nowrap; }
        /* Custom pagination removed in favor of DataTables */
        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); top: 60px; height: calc(100% - 60px); }
            .sidebar.open { transform: translateX(0); box-shadow: 0 6px 24px rgba(2,6,23,0.2); }
            .content { margin-left: 0; }
            #menu-btn { display:inline-block; }
        }
        .backdrop { position: fixed; left:0; right:0; bottom:0; top:60px; background: rgba(2,6,23,0.35); display:none; z-index: 900; }
        .backdrop.show { display:block; }
	</style>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css" />
</head>
<body>
	<aside class="sidebar">
        <div class="brand">Relief Hub</div>
        <nav>
			<a href="./dashboard.php" class="<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php' ? 'active' : '' ?>" aria-current="<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'dashboard.php' ? 'page' : 'false' ?>">Dashboard</a>
			<a href="./reports.php" class="<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'reports.php' ? 'active' : '' ?>">Reports</a>
        </nav>
    </aside>
    <div class="backdrop" id="backdrop"></div>
    <div class="content">
    <header>
        <h1>Dashboard</h1>
        <button id="menu-btn" aria-label="Toggle menu">☰</button>
	</header>
	<main>
		<section class="card kpi-card">
			<div class="kpis">
				<div class="kpi kpi-total">
					<div class="text">
						<div class="value" id="kpi-total"><?= $totalCount ?></div>
						<div class="label">Total</div>
					</div>
					<div class="icon" aria-hidden="true">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M9 6h6M7 10h10M7 14h10M7 18h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<rect x="3" y="3" width="18" height="18" rx="3" stroke="currentColor" stroke-width="2" fill="none"/>
						</svg>
					</div>
				</div>
				<div class="kpi kpi-active">
					<div class="text">
						<div class="value" id="kpi-active"><?= $activeCount ?></div>
						<div class="label">Active (est.)</div>
					</div>
					<div class="icon" aria-hidden="true">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M4 17c0-2.21 3.134-4 7-4s7 1.79 7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<circle cx="12" cy="8" r="3.5" stroke="currentColor" stroke-width="2"/>
						</svg>
					</div>
				</div>
				<div class="kpi kpi-last">
					<div class="text">
						<div class="value" id="kpi-24h"><?= $last24 ?></div>
						<div class="label">Last 24h</div>
					</div>
					<div class="icon" aria-hidden="true">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2"/>
							<path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
						</svg>
					</div>
				</div>
				<div class="kpi kpi-avg">
					<div class="text">
						<div class="value" id="kpi-avg"><?= number_format($avgPeople, 1) ?></div>
						<div class="label">Avg People</div>
					</div>
					<div class="icon" aria-hidden="true">
						<svg width="32" height="32" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M7 18c0-1.657 2.239-3 5-3s5 1.343 5 3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
							<path d="M4 18c0-1.105 1.79-2 4-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<path d="M6.5 11.5c1.105 0 2-.895 2-2s-.895-2-2-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<path d="M16 16c2.21 0 4 .895 4 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
							<path d="M17.5 11.5c-1.105 0-2-.895-2-2s.895-2 2-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
						</svg>
					</div>
				</div>
			</div>
		</section>
		<section class="card">
			<form method="get" class="filters" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
				<input name="q" value="<?= htmlspecialchars($q, ENT_QUOTES) ?>" placeholder="Search title or description" />
				<select name="urgency">
					<option value=""<?= $urgency === '' ? ' selected' : '' ?>>All urgencies</option>
					<option value="critical"<?= $urgency === 'critical' ? ' selected' : '' ?>>Critical</option>
					<option value="high"<?= $urgency === 'high' ? ' selected' : '' ?>>High</option>
					<option value="medium"<?= $urgency === 'medium' ? ' selected' : '' ?>>Medium</option>
					<option value="low"<?= $urgency === 'low' ? ' selected' : '' ?>>Low</option>
				</select>
				<input name="bbox" value="<?= htmlspecialchars($bbox, ENT_QUOTES) ?>" placeholder="bbox: minLon,minLat,maxLon,maxLat" />
				<select name="per_page">
					<?php foreach ([10,25,50,100,200] as $pp): ?>
						<option value="<?= $pp ?>"<?= $perPage === $pp ? ' selected' : '' ?>><?= $pp ?>/page</option>
					<?php endforeach; ?>
				</select>
				<button type="submit">Apply</button>
			</form>
		</section>
		<section class="card">
			<div class="scroll">
				<table id="requests-table" class="display" style="width:100%">
					<thead>
						<tr>
							<th>Title</th>
							<th>Type</th>
							<th>Urgency</th>
							<th>People</th>
							<th>Coords</th>
							<th>Created</th>
							<th>Status</th>
						</tr>
					</thead>
					<tbody id="rows">
					<?php foreach ($rows as $r): ?>
						<tr>
                            <td>
                                <a href="./request.php?id=<?= urlencode((string)$r['public_id']) ?>" target="_blank">
                                    <?= strtoupper(htmlspecialchars((string)$r['title'], ENT_QUOTES)) ?>
                                </a>
                            </td>
							<td><?= htmlspecialchars((string)$r['request_type'], ENT_QUOTES) ?></td>
							<td>
								<?php $u = (string)$r['urgency']; $cls = 'urgency-' . ($u ?: 'low'); ?>
								<span class="badge <?= $cls ?>"><?= htmlspecialchars($u, ENT_QUOTES) ?></span>
							</td>
							<td><?= (int)$r['people_affected'] ?></td>
							<td><?= number_format((float)$r['latitude'], 4) ?>, <?= number_format((float)$r['longitude'], 4) ?></td>
							<td><?= htmlspecialchars((string)$r['created_at'], ENT_QUOTES) ?></td>
							<?php $st = strtolower((string)$r['status']); $scls = $st === 'active' ? 'status-active' : ($st === 'withdrawn' ? 'status-withdrawn' : 'status-resolved'); ?>
							<td><span class="status-badge <?= $scls ?>"><?= htmlspecialchars($st, ENT_QUOTES) ?></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<!-- DataTables will handle pagination -->
		</section>
    </main>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script>
		(function(){
			const $rows = document.getElementById('rows');
			const $total = document.getElementById('kpi-total');
			const $active = document.getElementById('kpi-active');
			const $last24 = document.getElementById('kpi-24h');
			const $avg = document.getElementById('kpi-avg');

			let dataTable = null;
			function escapeRegex(text){
				return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			}

			function clsUrgency(u){ return 'badge urgency-' + (u || 'low'); }

			function buildPartialUrl(){
				const url = new URL(location.href);
				url.searchParams.set('partial', '1');
				return url.toString();
			}

			async function refresh(){
				try {
					const res = await fetch(buildPartialUrl(), { cache: 'no-store' });
					if (!res.ok) throw new Error('HTTP ' + res.status);
					const data = await res.json();

					// Initialize DataTable once
					if (!dataTable) {
						dataTable = new $.fn.dataTable.Api($('#requests-table').DataTable({
							pageLength: 25,
							order: [[5, 'desc']],
							columns: [
								{ title: 'Title' },
								{ title: 'Type' },
								{ title: 'Urgency' },
								{ title: 'People', className: 'dt-body-right' },
								{ title: 'Coords' },
								{ title: 'Created' },
								{ title: 'Status' }
							]
						}));

						// Hook DataTables urgency filter to the existing select
						const dtUrgSel = document.querySelector('form.filters select[name="urgency"]');
						const applyUrgencyFilter = function(){
							const v = (dtUrgSel?.value || '').trim();
							if (!dataTable) return;
							if (v === '') {
								dataTable.column(2).search('').draw(false);
							} else {
								dataTable.column(2).search('^' + escapeRegex(v) + '$', true, false).draw(false);
							}
						};
						dtUrgSel?.addEventListener('change', function(e){
							// Apply client-side filter without submitting the form
							e.preventDefault();
							applyUrgencyFilter();
						});
						// Apply on load to honor current selection
						applyUrgencyFilter();
					}

					// Replace rows via DataTables API
					dataTable.clear();
					for (const r of data.rows || []){
						const st = String(r.status || '').toLowerCase();
						const scls = st === 'active' ? 'status-active' : (st === 'withdrawn' ? 'status-withdrawn' : 'status-resolved');
						dataTable.row.add([
							`<a href=\"./request.php?id=${encodeURIComponent(r.public_id)}\" target=\"_blank\">${r.title}</a>`,
							r.request_type,
							`<span class=\"${clsUrgency(r.urgency)}\">${r.urgency}</span>`,
							String(r.people_affected),
							`${Number(r.latitude).toFixed(4)}, ${Number(r.longitude).toFixed(4)}`,
							r.created_at,
							`<span class=\"status-badge ${scls}\">${st}</span>`
						]);
					}
					dataTable.draw(false);
					// Re-apply urgency filter after data reloads
					const urgSel = document.querySelector('form.filters select[name="urgency"]');
					if (urgSel) {
						const v = urgSel.value.trim();
						if (v === '') { dataTable.column(2).search(''); }
						else { dataTable.column(2).search('^' + escapeRegex(v) + '$', true, false); }
						dataTable.draw(false);
					}
					if (data.kpis){
						$total.textContent = String(data.kpis.total ?? 0);
						$active.textContent = String(data.kpis.active ?? 0);
						$last24.textContent = String(data.kpis.last24h ?? 0);
						$avg.textContent = (data.kpis.avg ?? 0).toFixed ? data.kpis.avg.toFixed(1) : String(data.kpis.avg);
					}
				} catch (e) { console.error(e); }
			}

			refresh();
			setInterval(refresh, 3000);

			// Sidebar toggle for mobile
			const menuBtn = document.getElementById('menu-btn');
            const sidebar = document.querySelector('.sidebar');
            const backdrop = document.getElementById('backdrop');
			menuBtn?.addEventListener('click', function(){
                const open = sidebar?.classList.toggle('open');
                if (open) { backdrop?.classList.add('show'); } else { backdrop?.classList.remove('show'); }
			});
            backdrop?.addEventListener('click', function(){
                sidebar?.classList.remove('open');
                backdrop?.classList.remove('show');
            });
		})();
    </script>
    </div>
</body>
</html>


