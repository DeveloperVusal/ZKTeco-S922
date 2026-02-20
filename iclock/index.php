<?php
date_default_timezone_set("Asia/Baku");

// ====== DB CONFIG ======
$dbHost = "localhost";
$dbName = "u879108216_iclock";
$dbUser = "u879108216_iclock";
$dbPass = "@9XeD5=9/Pp";

$pdo = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ====== ROUTING ======
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$deviceSN = $_GET['SN'] ?? 'UNKNOWN';
$rawData  = file_get_contents("php://input");

$logFile = __DIR__ . "/log.txt";

// 1) getrequest
if (strpos($path, "/iclock/getrequest") !== false) {
    echo "OK";
    exit;
}

// 2) cdata
if (strpos($path, "/iclock/cdata") !== false && $method === "POST") {

    // ---- log.txt-ə yaz (debug) ----
    $logEntry  = "============================\n";
    $logEntry .= "Time: " . date("Y-m-d H:i:s") . "\n";
    $logEntry .= "Device SN: " . $deviceSN . "\n";
    $logEntry .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n";
    $logEntry .= "RAW DATA:\n" . $rawData . "\n\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

    // ---- parse və DB-ə yaz ----
    $lines = preg_split("/\r\n|\n|\r/", trim($rawData));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "") continue;

        // OPLOG sətri
        if (stripos($line, "OPLOG") === 0) {
            // məsələn: OPLOG 0  0  2026-02-17 06:49:51  0 0 0 0
            $parts = preg_split('/\s+/', $line);

            // tarix adətən 4-cü element olur (OPLOG=0, sonra 0,0, sonra date)
            $dt = null;
            // təhlükəsiz axtarış: hissələrdən DATETIME formatında olanı tap
            foreach ($parts as $i => $p) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $p) && isset($parts[$i+1]) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $parts[$i+1])) {
                    $dt = $p . " " . $parts[$i+1];
                    break;
                }
                if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $p)) {
                    $dt = $p;
                    break;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO oplogs (device_sn, event_time, raw_payload) VALUES (?, ?, ?)");
            $stmt->execute([$deviceSN, $dt, $line]);
            continue;
        }

        // Normal access log sətri (tablarla gəlir)
        // məsələn: 1\t2026-02-17 06:52:07\t0\t1\t0\t0\t0\t0\t0\t0
        $parts = preg_split("/\t+/", $line);
        $parts = array_values(array_filter($parts, fn($x) => $x !== "")); // boşları at

        // minimum gözlədiyimiz: user_id + datetime
        $userId = $parts[0] ?? null;
        $dt     = $parts[1] ?? null;

        // qalan 8 kolon (əgər azdırsa null qalacaq)
        $col1 = $parts[2] ?? null;
        $col2 = $parts[3] ?? null;
        $col3 = $parts[4] ?? null;
        $col4 = $parts[5] ?? null;
        $col5 = $parts[6] ?? null;
        $col6 = $parts[7] ?? null;
        $col7 = $parts[8] ?? null;
        $col8 = $parts[9] ?? null;

        $stmt = $pdo->prepare("
            INSERT INTO access_logs
            (device_sn, event_time, user_id, col1, col2, col3, col4, col5, col6, col7, col8, raw_payload)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$deviceSN, $dt, $userId, $col1, $col2, $col3, $col4, $col5, $col6, $col7, $col8, $line]);
    }

    echo "OK";
    exit;
}

echo "ZKTeco Push Server Running";
