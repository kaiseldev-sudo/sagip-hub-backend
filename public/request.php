<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';

use ReliefHub\Backend\Env;
use ReliefHub\Backend\Database;

$id = isset($_GET['id']) ? (string)$_GET['id'] : '';
if ($id === '') { http_response_code(400); echo 'Missing id'; exit; }

try {
	$env = Env::load(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . '.env');
	$pdo = Database::connect($env);
} catch (Throwable $e) {
	http_response_code(500);
	echo 'Failed to initialize';
	exit;
}

$stmt = $pdo->prepare("SELECT public_id, title, description, request_type, urgency, people_affected, latitude, longitude, status, created_at, updated_at FROM v_public_help_requests WHERE public_id = ? LIMIT 1");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(404); echo 'Not found'; exit; }

$lat = (float)$row['latitude'];
$lng = (float)$row['longitude'];
$title = (string)$row['title'];
$desc = (string)$row['description'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?> · Request</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700;800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <style>
        :root { --bg:#f8fafc; --card:#ffffff; --muted:#64748b; --text:#0f172a; --accent:#0ea5e9; --border:#e5e7eb; }
        body { margin:0; font-family: Manrope, Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; background: var(--bg); color: var(--text); }
        header { padding: 16px 20px; border-bottom: 1px solid var(--border); display:flex; align-items:center; justify-content:space-between; position: sticky; top:0; background:#ffffffcc; backdrop-filter: blur(6px); z-index:10; }
        header h1 { margin:0; font-size: 18px; letter-spacing:0.2px; text-transform: uppercase; }
        main { padding: 20px; display:grid; grid-template-columns: 1fr; gap:16px; }
        @media (min-width: 1000px) { main { grid-template-columns: 1fr 1.2fr; } }
        @media (max-width: 700px) { #map { height: 360px; } }
        @media (max-width: 600px) { .meta { grid-template-columns: 1fr; } }
        .card { background: var(--card); border:1px solid var(--border); border-radius:12px; padding:16px; box-shadow: 0 6px 18px rgba(2,6,23,0.05); }
        #map { height: 460px; border-radius: 10px; }
        .actions a, .btn { background: var(--accent); color:#ffffff; border:none; border-radius:8px; padding:8px 12px; font-weight:700; text-decoration:none; cursor:pointer; }
        .meta { display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:10px; }
        .label { color: var(--muted); font-size:12px; }
        .value { font-size:16px; word-break: break-word; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; font-weight:700; }
        .urgency-critical { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .urgency-high { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
        .urgency-medium { background:#cffafe; color:#155e75; border:1px solid #a5f3fc; }
        .urgency-low { background:#dcfce7; color:#065f46; border:1px solid #bbf7d0; }
        .status-badge { display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:700; text-transform:capitalize; }
        .status-active { background:#dcfce7; color:#065f46; border:1px solid #bbf7d0; }
        .status-withdrawn { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .status-resolved { background:#e0e7ff; color:#3730a3; border:1px solid #c7d2fe; }
        .row { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .muted { color: var(--muted); font-size:12px; }
        @media (max-width: 480px) { .row .btn { flex: 1 1 100%; text-align:center; } }
    </style>
    <script>
        // Determine base path for API calls
        function getBasePath(){
            var path = location.pathname;
            var parts = path.split('/');
            parts.pop();
            return parts.join('/');
        }
    </script>
</head>
<body>
	<header>
		<h1><?= htmlspecialchars($title, ENT_QUOTES) ?></h1>
		<div class="actions">
			<a href="./dashboard.php">Back to dashboard</a>
		</div>
	</header>
    <main>
        <section class="card">
            <div class="label">Title</div>
            <div class="value" id="v-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></div>
            <div class="label" style="margin-top:8px;">Description</div>
            <div class="value" id="v-desc"><?= nl2br(htmlspecialchars($desc, ENT_QUOTES)) ?></div>
            <div class="meta">
                <div>
                    <div class="label">Type</div>
                    <div class="value" id="v-type"><?= htmlspecialchars((string)$row['request_type'], ENT_QUOTES) ?></div>
                </div>
                <div>
                    <div class="label">Urgency</div>
                    <?php $u = (string)$row['urgency']; $ucl = 'urgency-' . ($u ?: 'low'); ?>
                    <div class="value"><span class="badge <?= $ucl ?>" id="v-urgency"><?= htmlspecialchars($u, ENT_QUOTES) ?></span></div>
                </div>
                <div>
                    <div class="label">People affected</div>
                    <div class="value" id="v-people"><?= (int)$row['people_affected'] ?></div>
                </div>
                <div>
                    <div class="label">Status</div>
                    <?php $st = strtolower((string)$row['status']); $scl = $st === 'active' ? 'status-active' : ($st === 'withdrawn' ? 'status-withdrawn' : 'status-resolved'); ?>
                    <div class="value"><span class="status-badge <?= $scl ?>" id="v-status"><?= htmlspecialchars($st, ENT_QUOTES) ?></span></div>
                </div>
                <div>
                    <div class="label">Coordinates</div>
                    <div class="value"><span id="v-coords"><?= number_format($lat, 6) ?>, <?= number_format($lng, 6) ?></span></div>
                </div>
                <div>
                    <div class="label">Timestamps</div>
                    <div class="value muted">Created: <span id="v-created"><?= htmlspecialchars((string)$row['created_at'], ENT_QUOTES) ?></span><br/>Updated: <span id="v-updated"><?= htmlspecialchars((string)$row['updated_at'], ENT_QUOTES) ?></span></div>
                </div>
            </div>
            <div class="row">
                <a class="btn" target="_blank" href="https://www.google.com/maps/dir/?api=1&destination=<?= rawurlencode($lat . ',' . $lng) ?>&destination_place_id=&travelmode=driving">Google Maps</a>
                <a class="btn" target="_blank" href="https://www.waze.com/ul?ll=<?= rawurlencode($lat . ',' . $lng) ?>&navigate=yes">Waze</a>
                <a class="btn" target="_blank" href="https://www.openstreetmap.org/directions?engine=fossgis_osrm_car&route=;<?= rawurlencode($lat . ',' . $lng) ?>">OSM</a>
            </div>
        </section>
        <section class="card">
			<div id="map"></div>
		</section>
	</main>
	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
	<script>
		const lat = <?= json_encode($lat) ?>;
		const lng = <?= json_encode($lng) ?>;
		const title = <?= json_encode($title) ?>;
		const map = L.map('map').setView([lat, lng], 14);
		L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; OpenStreetMap contributors'
		}).addTo(map);
		L.marker([lat, lng]).addTo(map).bindPopup(title).openPopup();

        // Periodically refresh the request details from the public API
        async function refresh(){
            try {
                const url = getBasePath().replace(/\/$/, '') + '/requests/' + encodeURIComponent(<?= json_encode($id) ?>);
                const res = await fetch(url, { cache: 'no-store' });
                if (!res.ok) return;
                const r = await res.json();
                document.getElementById('v-title').textContent = r.title || '';
                document.getElementById('v-desc').innerHTML = (r.description || '').replace(/\n/g, '<br />');
                document.getElementById('v-type').textContent = r.request_type || '';
                document.getElementById('v-people').textContent = String(r.people_affected || 0);
                document.getElementById('v-created').textContent = r.created_at || '';
                document.getElementById('v-updated').textContent = r.updated_at || '';

                const u = String(r.urgency || 'low');
                const uSpan = document.getElementById('v-urgency');
                uSpan.textContent = u;
                uSpan.className = 'badge ' + 'urgency-' + u;

                const s = String((r.status || '').toLowerCase());
                const sSpan = document.getElementById('v-status');
                sSpan.textContent = s;
                sSpan.className = 'status-badge ' + (s === 'active' ? 'status-active' : (s === 'withdrawn' ? 'status-withdrawn' : 'status-resolved'));

                if (typeof r.latitude === 'number' && typeof r.longitude === 'number'){
                    document.getElementById('v-coords').textContent = r.latitude.toFixed(6) + ', ' + r.longitude.toFixed(6);
                }
            } catch (_) {}
        }
        setInterval(refresh, 5000);
        refresh();
	</script>
</body>
</html>


