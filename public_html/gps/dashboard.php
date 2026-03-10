<?php
/**
 * Teltonika FMM640 GPS Dashboard
 * logs/gps_data.log faylından məlumat oxuyur və vizuallaşdırır
 */

define('LOG_FILE', __DIR__ . '/logs/gps_data.log');
define('MAX_RECORDS', 500);

// ─── AJAX / JSON endpoint ────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    echo json_encode(parseLogFile($_GET['api'], $_GET['imei'] ?? null, (int)($_GET['limit'] ?? 100)));
    exit;
}

// ─── Log parser ──────────────────────────────────────────────────────────────
function parseLogFile(string $type, ?string $filterImei = null, int $limit = 100): array
{
    if (!file_exists(LOG_FILE)) {
        return ['error' => 'Log faylı tapılmadı: ' . LOG_FILE, 'data' => []];
    }

    $lines   = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $records = [];
    $imeis   = [];

    foreach (array_reverse($lines) as $line) {
        if (strpos($line, '[GPS]') === false) continue;

        $r = parseGpsLine($line);
        if (!$r) continue;

        $imeis[$r['imei']] = true;

        if ($filterImei && $r['imei'] !== $filterImei) continue;

        $records[] = $r;
        if (count($records) >= $limit) break;
    }

    if ($type === 'records') {
        return ['data' => array_values($records)];
    }

    if ($type === 'imeis') {
        return ['data' => array_keys($imeis)];
    }

    if ($type === 'stats') {
        return computeStats($records);
    }

    return ['data' => []];
}

function parseGpsLine(string $line): ?array
{
    // [2024-01-15 10:30:23] [GPS] IMEI=... | RecordTime=... | Lat=... | ...
    if (!preg_match('/\[(.+?)\] \[GPS\] (.+)/', $line, $m)) return null;

    $logTime = $m[1];
    $parts   = $m[2];

    $fields = [];
    foreach (explode(' | ', $parts) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        $fields[trim($k)] = trim($v);
    }

    if (empty($fields['IMEI']) || empty($fields['Lat'])) return null;

    $io = [];
    foreach ($fields as $k => $v) {
        if (preg_match('/^IO\[(\d+)\]$/', $k, $im)) {
            $io[(int)$im[1]] = (int)$v;
        }
    }

    return [
        'log_time'   => $logTime,
        'imei'       => $fields['IMEI'],
        'rec_time'   => $fields['RecordTime'] ?? $logTime,
        'lat'        => (float)($fields['Lat'] ?? 0),
        'lng'        => (float)($fields['Lng'] ?? 0),
        'alt'        => (int)preg_replace('/[^\d]/', '', $fields['Alt'] ?? '0'),
        'angle'      => (int)preg_replace('/[^\d]/', '', $fields['Angle'] ?? '0'),
        'speed'      => (int)preg_replace('/[^\d]/', '', $fields['Speed'] ?? '0'),
        'sats'       => (int)($fields['Sats'] ?? 0),
        'ignition'   => $io[239] ?? null,
        'movement'   => $io[240] ?? null,
        'io'         => $io,
    ];
}

function computeStats(array $records): array
{
    if (!$records) return ['total' => 0];

    $speeds     = array_column($records, 'speed');
    $alts       = array_column($records, 'alt');
    $distances  = 0;

    for ($i = 1; $i < count($records); $i++) {
        $distances += haversine(
            $records[$i - 1]['lat'], $records[$i - 1]['lng'],
            $records[$i]['lat'],     $records[$i]['lng']
        );
    }

    return [
        'total'        => count($records),
        'max_speed'    => max($speeds),
        'avg_speed'    => round(array_sum($speeds) / count($speeds), 1),
        'max_alt'      => max($alts),
        'total_km'     => round($distances / 1000, 2),
        'first_time'   => end($records)['rec_time'],
        'last_time'    => $records[0]['rec_time'],
    ];
}

function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $R  = 6371000;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1);
    $dl = deg2rad($lng2 - $lng1);
    $a  = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ─── İlk IMEI-ləri yüklə ─────────────────────────────────────────────────────
$imeiList = parseLogFile('imeis')['data'] ?? [];
$logExists = file_exists(LOG_FILE);
?>
<!DOCTYPE html>
<html lang="az">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FMM640 GPS Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Syne:wght@400;600;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  :root {
    --bg:      #0b0f17;
    --surface: #111827;
    --card:    #161d2e;
    --border:  #1e2d45;
    --accent:  #00d4ff;
    --green:   #22c55e;
    --red:     #f43f5e;
    --yellow:  #fbbf24;
    --text:    #e2e8f0;
    --muted:   #64748b;
    --font:    'Syne', sans-serif;
    --mono:    'JetBrains Mono', monospace;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font);
    min-height: 100vh;
    overflow-x: hidden;
  }

  /* ── Header ── */
  .header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 28px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 100;
  }

  .logo {
    display: flex; align-items: center; gap: 10px;
    font-size: 18px; font-weight: 800; letter-spacing: -0.5px;
  }

  .logo-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 10px var(--accent);
    animation: pulse 2s infinite;
  }

  @keyframes pulse {
    0%,100% { opacity: 1; transform: scale(1); }
    50%      { opacity: .5; transform: scale(1.4); }
  }

  .header-right {
    display: flex; align-items: center; gap: 12px;
  }

  .imei-select {
    background: var(--card); border: 1px solid var(--border);
    color: var(--text); font-family: var(--mono); font-size: 13px;
    padding: 7px 12px; border-radius: 8px; outline: none;
    cursor: pointer;
  }

  .imei-select:focus { border-color: var(--accent); }

  .btn {
    padding: 8px 16px; border-radius: 8px; border: none;
    font-family: var(--font); font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .2s;
  }

  .btn-primary { background: var(--accent); color: #000; }
  .btn-primary:hover { opacity: .85; transform: translateY(-1px); }
  .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .btn-ghost:hover { border-color: var(--accent); color: var(--accent); }

  .live-badge {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; font-weight: 600; color: var(--green);
    background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.25);
    padding: 5px 11px; border-radius: 20px;
  }

  .live-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: var(--green); animation: pulse 1.5s infinite;
  }

  /* ── Layout ── */
  .main { display: grid; grid-template-columns: 340px 1fr; height: calc(100vh - 57px); }

  /* ── Sidebar ── */
  .sidebar {
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    overflow: hidden;
  }

  .stats-grid {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 1px; background: var(--border);
    border-bottom: 1px solid var(--border);
  }

  .stat-card {
    background: var(--card);
    padding: 16px;
    display: flex; flex-direction: column; gap: 4px;
  }

  .stat-label {
    font-size: 10px; text-transform: uppercase;
    letter-spacing: 1px; color: var(--muted); font-weight: 600;
  }

  .stat-value {
    font-family: var(--mono); font-size: 22px;
    font-weight: 600; color: var(--accent);
    line-height: 1;
  }

  .stat-unit { font-size: 11px; color: var(--muted); }

  /* ── Log list ── */
  .log-header {
    padding: 14px 16px;
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: 1px; color: var(--muted);
    border-bottom: 1px solid var(--border);
    display: flex; justify-content: space-between; align-items: center;
  }

  .log-list { overflow-y: auto; flex: 1; }

  .log-item {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(30,45,69,.6);
    cursor: pointer;
    transition: background .15s;
    display: flex; flex-direction: column; gap: 5px;
  }

  .log-item:hover, .log-item.active {
    background: rgba(0,212,255,.05);
    border-left: 2px solid var(--accent);
    padding-left: 14px;
  }

  .log-item-top {
    display: flex; justify-content: space-between; align-items: center;
  }

  .log-time { font-family: var(--mono); font-size: 11px; color: var(--muted); }

  .speed-badge {
    font-family: var(--mono); font-size: 11px; font-weight: 600;
    padding: 2px 7px; border-radius: 4px;
  }

  .speed-stop  { background: rgba(100,116,139,.2); color: var(--muted); }
  .speed-slow  { background: rgba(34,197,94,.15);  color: var(--green); }
  .speed-med   { background: rgba(251,191,36,.15); color: var(--yellow); }
  .speed-fast  { background: rgba(244,63,94,.15);  color: var(--red); }

  .log-coords {
    font-family: var(--mono); font-size: 12px; color: var(--text);
  }

  .log-meta {
    display: flex; gap: 10px;
    font-size: 11px; color: var(--muted);
  }

  .ign-on  { color: var(--green); }
  .ign-off { color: var(--red); }

  /* ── Map ── */
  .map-section { display: flex; flex-direction: column; }

  #map { flex: 1; }

  .map-toolbar {
    background: var(--surface);
    border-top: 1px solid var(--border);
    padding: 10px 16px;
    display: flex; gap: 8px; align-items: center;
    font-size: 12px; color: var(--muted);
  }

  /* ── Detail panel ── */
  .detail-panel {
    position: absolute; right: 16px; top: 80px;
    width: 260px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 16px;
    z-index: 50;
    display: none;
    box-shadow: 0 20px 60px rgba(0,0,0,.5);
  }

  .detail-panel.show { display: block; animation: slideIn .2s ease; }

  @keyframes slideIn {
    from { opacity: 0; transform: translateY(-8px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .detail-title {
    font-size: 13px; font-weight: 700; color: var(--accent);
    margin-bottom: 12px; padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
  }

  .detail-row {
    display: flex; justify-content: space-between;
    padding: 5px 0;
    font-size: 12px; border-bottom: 1px solid rgba(30,45,69,.4);
  }

  .detail-row:last-child { border-bottom: none; }
  .detail-key  { color: var(--muted); }
  .detail-val  { font-family: var(--mono); color: var(--text); font-weight: 600; }

  .close-btn {
    position: absolute; top: 10px; right: 10px;
    background: none; border: none; color: var(--muted);
    cursor: pointer; font-size: 16px; line-height: 1;
  }
  .close-btn:hover { color: var(--text); }

  /* ── No data ── */
  .no-data {
    display: flex; flex-direction: column; align-items: center;
    justify-content: center; gap: 12px; height: 200px;
    color: var(--muted); font-size: 13px; text-align: center;
    padding: 20px;
  }

  .no-data-icon { font-size: 36px; opacity: .4; }

  /* ── Scrollbar ── */
  ::-webkit-scrollbar { width: 4px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

  /* ── Leaflet override ── */
  .leaflet-popup-content-wrapper {
    background: var(--card) !important; color: var(--text) !important;
    border: 1px solid var(--border) !important; border-radius: 8px !important;
    font-family: var(--mono) !important; font-size: 12px !important;
    box-shadow: 0 10px 40px rgba(0,0,0,.5) !important;
  }
  .leaflet-popup-tip { background: var(--card) !important; }

  @media (max-width: 768px) {
    .main { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
    .sidebar { max-height: 300px; }
  }
</style>
</head>
<body>

<div class="header">
  <div class="logo">
    <div class="logo-dot"></div>
    FMM640 Dashboard
  </div>
  <div class="header-right">
    <select class="imei-select" id="imeiSelect">
      <option value="">— IMEI seçin —</option>
      <?php foreach ($imeiList as $imei): ?>
        <option value="<?= htmlspecialchars($imei) ?>"><?= htmlspecialchars($imei) ?></option>
      <?php endforeach; ?>
      <?php if (!$imeiList): ?>
        <option value="" disabled>Log faylı boşdur</option>
      <?php endif; ?>
    </select>
    <button class="btn btn-primary" onclick="loadData()">Yüklə</button>
    <button class="btn btn-ghost" onclick="startAutoRefresh()" id="autoBtn">⟳ Auto</button>
    <div class="live-badge" id="liveBadge" style="display:none">
      <div class="live-dot"></div> CANLI
    </div>
  </div>
</div>

<div class="main">

  <!-- Sidebar -->
  <div class="sidebar">
    <div class="stats-grid" id="statsGrid">
      <div class="stat-card">
        <div class="stat-label">Qeyd</div>
        <div class="stat-value" id="sTotalKm">—</div>
        <div class="stat-unit">ümumi qeyd</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Məsafə</div>
        <div class="stat-value" id="sDistance">—</div>
        <div class="stat-unit">km</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Maks Sürət</div>
        <div class="stat-value" id="sMaxSpeed">—</div>
        <div class="stat-unit">km/h</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Ort Sürət</div>
        <div class="stat-value" id="sAvgSpeed">—</div>
        <div class="stat-unit">km/h</div>
      </div>
    </div>

    <div class="log-header">
      <span>GPS Qeydlər</span>
      <span id="recordCount" style="color:var(--accent)">0</span>
    </div>

    <div class="log-list" id="logList">
      <div class="no-data">
        <div class="no-data-icon">📡</div>
        IMEI seçin və məlumatları yükləyin
      </div>
    </div>
  </div>

  <!-- Map -->
  <div class="map-section" style="position:relative">
    <div id="map"></div>

    <!-- Detail panel -->
    <div class="detail-panel" id="detailPanel">
      <button class="close-btn" onclick="document.getElementById('detailPanel').classList.remove('show')">✕</button>
      <div class="detail-title">📍 Qeyd Detalları</div>
      <div id="detailContent"></div>
    </div>

    <div class="map-toolbar">
      <span>🗺 Xəritə</span>
      <span style="margin-left:auto" id="mapInfo">Məlumat yoxdur</span>
      <button class="btn btn-ghost" onclick="fitMap()" style="padding:4px 10px;font-size:11px">Hamısı</button>
    </div>
  </div>

</div>

<script>
// ── Map init ────────────────────────────────────────────────────────────────
const map = L.map('map', { zoomControl: true }).setView([40.409, 49.867], 10);

L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
  attribution: '© OpenStreetMap © CARTO',
  maxZoom: 19
}).addTo(map);

let polyline    = null;
let markers     = [];
let allRecords  = [];
let autoTimer   = null;
let activeIdx   = -1;

// ── Custom icon ─────────────────────────────────────────────────────────────
function makeIcon(speed, isLast) {
  const color = speed === 0 ? '#64748b'
    : speed < 30  ? '#22c55e'
    : speed < 80  ? '#fbbf24' : '#f43f5e';

  const size = isLast ? 16 : 10;
  const html = isLast
    ? `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 0 12px ${color}"></div>`
    : `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};opacity:.8"></div>`;

  return L.divIcon({ html, className: '', iconSize: [size, size], iconAnchor: [size/2, size/2] });
}

// ── Load data ────────────────────────────────────────────────────────────────
async function loadData() {
  const imei = document.getElementById('imeiSelect').value;
  if (!imei) { alert('Zəhmət olmasa IMEI seçin'); return; }

  const [recRes, statRes] = await Promise.all([
    fetch(`?api=records&imei=${imei}&limit=200`).then(r => r.json()),
    fetch(`?api=stats&imei=${imei}&limit=200`).then(r => r.json()),
  ]);

  allRecords = recRes.data || [];
  renderList(allRecords);
  renderMap(allRecords);
  renderStats(statRes);
  document.getElementById('recordCount').textContent = allRecords.length;
}

// ── Render list ──────────────────────────────────────────────────────────────
function renderList(records) {
  const el = document.getElementById('logList');
  if (!records.length) {
    el.innerHTML = '<div class="no-data"><div class="no-data-icon">🔍</div>Bu IMEI üçün qeyd yoxdur</div>';
    return;
  }

  el.innerHTML = records.map((r, i) => {
    const sp = r.speed;
    const sc = sp === 0 ? 'speed-stop' : sp < 30 ? 'speed-slow' : sp < 80 ? 'speed-med' : 'speed-fast';
    const ign = r.ignition !== null
      ? `<span class="${r.ignition ? 'ign-on' : 'ign-off'}">${r.ignition ? '🔑 ON' : '🔑 OFF'}</span>` : '';

    return `
    <div class="log-item" id="li-${i}" onclick="selectRecord(${i})">
      <div class="log-item-top">
        <span class="log-time">${r.rec_time}</span>
        <span class="speed-badge ${sc}">${sp} km/h</span>
      </div>
      <div class="log-coords">${r.lat.toFixed(5)}, ${r.lng.toFixed(5)}</div>
      <div class="log-meta">
        <span>↑ ${r.alt}m</span>
        <span>🛰 ${r.sats}</span>
        <span>⬆ ${r.angle}°</span>
        ${ign}
      </div>
    </div>`;
  }).join('');
}

// ── Render map ───────────────────────────────────────────────────────────────
function renderMap(records) {
  markers.forEach(m => map.removeLayer(m));
  markers = [];
  if (polyline) { map.removeLayer(polyline); polyline = null; }
  if (!records.length) return;

  const coords = records.map(r => [r.lat, r.lng]);

  polyline = L.polyline(coords, {
    color: '#00d4ff', weight: 2, opacity: .6,
    dashArray: null
  }).addTo(map);

  records.forEach((r, i) => {
    const isLast = i === 0;
    const m = L.marker([r.lat, r.lng], { icon: makeIcon(r.speed, isLast) })
      .addTo(map)
      .on('click', () => selectRecord(i));
    markers.push(m);
  });

  fitMap();
  document.getElementById('mapInfo').textContent = `${records.length} qeyd göstərilir`;
}

function fitMap() {
  if (!allRecords.length) return;
  const coords = allRecords.map(r => [r.lat, r.lng]);
  map.fitBounds(L.latLngBounds(coords).pad(0.1));
}

// ── Select record ─────────────────────────────────────────────────────────────
function selectRecord(i) {
  const r = allRecords[i];
  if (!r) return;

  // Sidebar aktiv
  if (activeIdx >= 0) {
    const prev = document.getElementById(`li-${activeIdx}`);
    if (prev) prev.classList.remove('active');
  }
  activeIdx = i;
  const cur = document.getElementById(`li-${i}`);
  if (cur) { cur.classList.add('active'); cur.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); }

  // Xəritə uçuş
  map.setView([r.lat, r.lng], 15, { animate: true, duration: .6 });

  // Detail panel
  const ioHtml = Object.entries(r.io).map(([k, v]) =>
    `<div class="detail-row"><span class="detail-key">IO[${k}]</span><span class="detail-val">${v}</span></div>`
  ).join('') || '<div style="font-size:11px;color:var(--muted)">IO yoxdur</div>';

  document.getElementById('detailContent').innerHTML = `
    <div class="detail-row"><span class="detail-key">Vaxt</span><span class="detail-val">${r.rec_time}</span></div>
    <div class="detail-row"><span class="detail-key">Lat</span><span class="detail-val">${r.lat.toFixed(6)}</span></div>
    <div class="detail-row"><span class="detail-key">Lng</span><span class="detail-val">${r.lng.toFixed(6)}</span></div>
    <div class="detail-row"><span class="detail-key">Sürət</span><span class="detail-val">${r.speed} km/h</span></div>
    <div class="detail-row"><span class="detail-key">Hündürlük</span><span class="detail-val">${r.alt} m</span></div>
    <div class="detail-row"><span class="detail-key">İstiqamət</span><span class="detail-val">${r.angle}°</span></div>
    <div class="detail-row"><span class="detail-key">Peyklər</span><span class="detail-val">${r.sats}</span></div>
    <div class="detail-row"><span class="detail-key">Alovlandırma</span><span class="detail-val ${r.ignition ? 'ign-on' : 'ign-off'}">${r.ignition !== null ? (r.ignition ? '✔ ON' : '✘ OFF') : '—'}</span></div>
    <div style="margin-top:8px;font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin-bottom:6px">IO Elementlər</div>
    ${ioHtml}
  `;
  document.getElementById('detailPanel').classList.add('show');
}

// ── Render stats ─────────────────────────────────────────────────────────────
function renderStats(s) {
  if (!s || !s.total) return;
  document.getElementById('sTotalKm').textContent  = s.total;
  document.getElementById('sDistance').textContent = s.total_km ?? '—';
  document.getElementById('sMaxSpeed').textContent = s.max_speed ?? '—';
  document.getElementById('sAvgSpeed').textContent = s.avg_speed ?? '—';
}

// ── Auto refresh ─────────────────────────────────────────────────────────────
function startAutoRefresh() {
  const btn  = document.getElementById('autoBtn');
  const live = document.getElementById('liveBadge');

  if (autoTimer) {
    clearInterval(autoTimer);
    autoTimer = null;
    btn.textContent = '⟳ Auto';
    btn.style.color = '';
    live.style.display = 'none';
    return;
  }

  autoTimer = setInterval(loadData, 10000);
  loadData();
  btn.textContent = '⏸ Dayandır';
  btn.style.color = 'var(--accent)';
  live.style.display = 'flex';
}

// ── IMEI dəyişdikdə avto yüklə ───────────────────────────────────────────────
document.getElementById('imeiSelect').addEventListener('change', () => {
  const imei = document.getElementById('imeiSelect').value;
  if (imei) loadData();
});

// İlk IMEI varsa avto seç
<?php if ($imeiList): ?>
document.getElementById('imeiSelect').value = <?= json_encode($imeiList[0]) ?>;
loadData();
<?php endif; ?>
</script>
</body>
</html>
