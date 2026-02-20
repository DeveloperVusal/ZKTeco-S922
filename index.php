<?php
// ui.php
declare(strict_types=1);
require __DIR__ . "/db.php";

function h(?string $s): string {
    return htmlspecialchars($s ?? "", ENT_QUOTES, "UTF-8");
}

// aktiv tab
$tab = $_GET["tab"] ?? "access";
if (!in_array($tab, ["access", "oplogs"], true)) $tab = "access";

// filterlər
$device   = $_GET["device"] ?? "";
$dateFrom = $_GET["from"] ?? "";
$dateTo   = $_GET["to"] ?? "";
$q        = trim($_GET["q"] ?? "");

// device siyahısı (hər iki cədvəldən birləşdirib)
$devices = $pdo->query("
    SELECT device_sn FROM (
        SELECT DISTINCT device_sn FROM access_logs
        UNION
        SELECT DISTINCT device_sn FROM oplogs
    ) t
    ORDER BY device_sn
")->fetchAll();

// --------- ACCESS LOGS SELECT ----------
$accessRows = [];
if ($tab === "access") {
    $sql = "SELECT id, device_sn, event_time, user_id,
                   col1, col2, col3, col4, col5, col6, col7, col8,
                   raw_payload, created_at
            FROM access_logs
            WHERE 1=1";
    $params = [];

    if ($device !== "") { $sql .= " AND device_sn = ?"; $params[] = $device; }
    if ($dateFrom !== "") { $sql .= " AND event_time >= ?"; $params[] = $dateFrom . " 00:00:00"; }
    if ($dateTo !== "") { $sql .= " AND event_time <= ?"; $params[] = $dateTo . " 23:59:59"; }
    if ($q !== "") {
        $sql .= " AND (user_id LIKE ? OR raw_payload LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $sql .= " ORDER BY event_time DESC, id DESC LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $accessRows = $stmt->fetchAll();
}

// --------- OPLOGS SELECT ----------
$oplogRows = [];
if ($tab === "oplogs") {
    $sql = "SELECT id, device_sn, event_time, raw_payload, created_at
            FROM oplogs
            WHERE 1=1";
    $params = [];

    if ($device !== "") { $sql .= " AND device_sn = ?"; $params[] = $device; }
    if ($dateFrom !== "") { $sql .= " AND event_time >= ?"; $params[] = $dateFrom . " 00:00:00"; }
    if ($dateTo !== "") { $sql .= " AND event_time <= ?"; $params[] = $dateTo . " 23:59:59"; }
    if ($q !== "") {
        $sql .= " AND raw_payload LIKE ?";
        $params[] = "%{$q}%";
    }

    $sql .= " ORDER BY event_time DESC, id DESC LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $oplogRows = $stmt->fetchAll();
}

// hazır link helper (tab dəyişəndə filterlər qalsın)
function buildUrl(array $override = []): string {
    $params = array_merge($_GET, $override);
    return "?" . http_build_query($params);
}

?>
<!doctype html>
<html lang="az">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ZKTeco Logs UI</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="m-0">ZKTeco Logs UI</h3>
    <div class="text-muted small">Limit: 300 sətir</div>
  </div>

  <!-- Tabs -->
  <ul class="nav nav-tabs mb-3">
    <li class="nav-item">
      <a class="nav-link <?= $tab === "access" ? "active" : "" ?>" href="<?=h(buildUrl(["tab"=>"access"]))?>">
        Access Logs
      </a>
    </li>
    <li class="nav-item">
      <a class="nav-link <?= $tab === "oplogs" ? "active" : "" ?>" href="<?=h(buildUrl(["tab"=>"oplogs"]))?>">
        OPLOGS
      </a>
    </li>
  </ul>

  <!-- Filters -->
  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2" method="get" action="">
        <input type="hidden" name="tab" value="<?=h($tab)?>">

        <div class="col-12 col-md-4">
          <label class="form-label">Device SN</label>
          <select class="form-select" name="device">
            <option value="">Hamısı</option>
            <?php foreach ($devices as $d): ?>
              <?php $sn = $d["device_sn"]; ?>
              <option value="<?=h($sn)?>" <?= $sn === $device ? "selected" : "" ?>>
                <?=h($sn)?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Tarixdən</label>
          <input type="date" class="form-control" name="from" value="<?=h($dateFrom)?>">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Tarixə</label>
          <input type="date" class="form-control" name="to" value="<?=h($dateTo)?>">
        </div>

        <div class="col-12 col-md-3">
          <label class="form-label">Axtar</label>
          <input type="text" class="form-control" name="q"
                 placeholder="<?= $tab === "access" ? "user_id və ya raw" : "raw içində" ?>"
                 value="<?=h($q)?>">
        </div>

        <div class="col-12 col-md-1 d-grid">
          <label class="form-label invisible">.</label>
          <button class="btn btn-primary" type="submit">Göstər</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Content -->
  <div class="card shadow-sm">
    <div class="card-body">

      <?php if ($tab === "access"): ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Event time</th>
                <th>Device</th>
                <th>User</th>
                <th>col1</th><th>col2</th><th>col3</th><th>col4</th>
                <th>col5</th><th>col6</th><th>col7</th><th>col8</th>
                <th>RAW</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$accessRows): ?>
              <tr><td colspan="13" class="text-muted">Məlumat tapılmadı.</td></tr>
            <?php endif; ?>

            <?php foreach ($accessRows as $r): ?>
              <tr>
                <td><?=h((string)$r["id"])?></td>
                <td><?=h((string)$r["event_time"])?></td>
                <td><?=h($r["device_sn"])?></td>
                <td><?=h($r["user_id"])?></td>
                <td><?=h($r["col1"])?></td>
                <td><?=h($r["col2"])?></td>
                <td><?=h($r["col3"])?></td>
                <td><?=h($r["col4"])?></td>
                <td><?=h($r["col5"])?></td>
                <td><?=h($r["col6"])?></td>
                <td><?=h($r["col7"])?></td>
                <td><?=h($r["col8"])?></td>
                <td style="max-width:360px;">
                  <code class="d-block text-truncate" title="<?=h($r["raw_payload"])?>"><?=h($r["raw_payload"])?></code>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>

      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped align-middle">
            <thead>
              <tr>
                <th>ID</th>
                <th>Event time</th>
                <th>Device</th>
                <th>RAW</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
            <?php if (!$oplogRows): ?>
              <tr><td colspan="5" class="text-muted">Məlumat tapılmadı.</td></tr>
            <?php endif; ?>

            <?php foreach ($oplogRows as $r): ?>
              <tr>
                <td><?=h((string)$r["id"])?></td>
                <td><?=h((string)$r["event_time"])?></td>
                <td><?=h($r["device_sn"])?></td>
                <td style="max-width:520px;">
                  <code class="d-block text-truncate" title="<?=h($r["raw_payload"])?>"><?=h($r["raw_payload"])?></code>
                </td>
                <td><?=h((string)$r["created_at"])?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div class="text-muted small mt-2">
        Məsələn:
        <code>?tab=oplogs&device=COCJ232360001&from=2026-02-17&to=2026-02-17</code>
      </div>

    </div>
  </div>

</div>
</body>
</html>
