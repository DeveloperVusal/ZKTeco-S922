<?php
/**
 * Teltonika FMM640 GPS Data Reader - TCP Server
 * Codec 8 / Codec 8 Extended / Codec 16 protokollarını dəstəkləyir
 * 
 * İstifadə: php teltonika_fmm640_server.php
 * Port: 5000 (aşağıda dəyişdirmək olar)
 */

define('SERVER_HOST', '0.0.0.0');
define('SERVER_PORT', 5000);
define('LOG_DIR', __DIR__ . '/logs');
define('LOG_FILE', LOG_DIR . '/gps_data.log');
define('RAW_LOG_FILE', LOG_DIR . '/raw_data.log');
define('MAX_CLIENTS', 10);
define('MAX_PROCESSES', 50);      // Maksimum fork prosesi (botlardan qorunma)
define('MAX_CONN_PER_IP', 3);     // Bir IP-dən maksimum eyni vaxtda bağlantı

// MySQL bağlantı məlumatları
define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'your_database');
define('DB_USER', 'your_user');
define('DB_PASS', 'your_password');
define('DB_CHARSET', 'utf8mb4');

// Log qovluğunu yarat
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

// ─── LOG FUNKSİYALARI ─────────────────────────────────────────────────────────

function writeLog(string $message, string $level = 'INFO', bool $rawOnly = false): void
{
    $pid       = getmypid();
    $timestamp = date('Y-m-d H:i:s');
    $logLine   = "[{$timestamp}] [PID:{$pid}] [{$level}] {$message}" . PHP_EOL;

    if (!$rawOnly) {
        file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
        echo $logLine;
    } else {
        file_put_contents(RAW_LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    }
}

function writeGpsLog(string $imei, array $record): void
{
    $timestamp = date('Y-m-d H:i:s');
    $lat       = number_format($record['latitude'],  6);
    $lng       = number_format($record['longitude'], 6);
    $recTime   = date('Y-m-d H:i:s', $record['timestamp']);

    $logLine = "[{$timestamp}] [GPS] IMEI={$imei} | RecordTime={$recTime} | "
        . "Lat={$lat} | Lng={$lng} | "
        . "Alt={$record['altitude']}m | Angle={$record['angle']}° | "
        . "Speed={$record['speed']}km/h | Sats={$record['satellites']}";

    if (!empty($record['io_elements'])) {
        $ioStr = [];
        foreach ($record['io_elements'] as $id => $val) {
            $ioStr[] = "IO[{$id}]={$val}";
        }
        $logLine .= ' | ' . implode(' | ', $ioStr);
    }

    $logLine .= PHP_EOL;
    file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    echo $logLine;
}

// ─── VERİLƏNLƏR BAZASI ────────────────────────────────────────────────────────

function dbConnect(): ?PDO
{
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        writeLog("DB bağlantı xətası: " . $e->getMessage(), 'ERROR');
        return null;
    }
}

/**
 * IMEI-yə görə fngr_pl_gps.id tap
 * Əgər IMEI tapılmasa → avtomatik əlavə et (status=1)
 */
function getGpsIdByImei(PDO $pdo, string $imei): ?int
{
    // Mövcud IMEI-ni tap
    $stmt = $pdo->prepare(
        'SELECT id FROM fngr_pl_gps WHERE imei = :imei LIMIT 1'
    );
    $stmt->execute([':imei' => $imei]);
    $row = $stmt->fetch();

    if ($row) {
        return (int)$row['id'];
    }

    // Yeni IMEI → avtomatik qeydiyyat
    $stmt = $pdo->prepare(
        'INSERT INTO fngr_pl_gps (imei, `key`, status, description)
         VALUES (:imei, :key, 1, :description)'
    );
    $stmt->execute([
        ':imei'        => $imei,
        ':key'         => 'auto_' . $imei,
        ':description' => 'Auto registered',
    ]);

    $newId = (int)$pdo->lastInsertId();
    writeLog("Yeni IMEI avtomatik qeydiyyatdan kecdi: {$imei} -> id={$newId}", 'INFO');

    return $newId;
}

/**
 * GPS qeydini gps_records cədvəlinə yaz
 */
function saveGpsRecord(PDO $pdo, int $gpsId, array $record): bool
{
    $io = $record['io_elements'] ?? [];

    $sql = "INSERT INTO gps_records
                (gps_id, record_time, lat, lng, altitude, angle, speed, sats,
                 io_22, io_71, io_240, io_21, io_200, io_239,
                 io_67, io_68, io_181, io_182, io_66, io_24, io_201)
            VALUES
                (:gps_id, :record_time, :lat, :lng, :altitude, :angle, :speed, :sats,
                 :io_22, :io_71, :io_240, :io_21, :io_200, :io_239,
                 :io_67, :io_68, :io_181, :io_182, :io_66, :io_24, :io_201)";

    $stmt = $pdo->prepare($sql);

    return $stmt->execute([
        ':gps_id'      => $gpsId,
        ':record_time' => date('Y-m-d H:i:s', $record['timestamp']),
        ':lat'         => $record['latitude'],
        ':lng'         => $record['longitude'],
        ':altitude'    => $record['altitude']   ?? null,
        ':angle'       => $record['angle']      ?? null,
        ':speed'       => $record['speed']      ?? null,
        ':sats'        => $record['satellites'] ?? null,
        ':io_22'       => $io[22]  ?? null,
        ':io_71'       => $io[71]  ?? null,
        ':io_240'      => $io[240] ?? null,
        ':io_21'       => $io[21]  ?? null,
        ':io_200'      => $io[200] ?? null,
        ':io_239'      => $io[239] ?? null,
        ':io_67'       => $io[67]  ?? null,
        ':io_68'       => $io[68]  ?? null,
        ':io_181'      => $io[181] ?? null,
        ':io_182'      => $io[182] ?? null,
        ':io_66'       => $io[66]  ?? null,
        ':io_24'       => $io[24]  ?? null,
        ':io_201'      => $io[201] ?? null,
    ]);
}

// ─── SOCKET YARDIMÇILARI ──────────────────────────────────────────────────────

function sendImeiResponse($socket, bool $accept = true): void
{
    socket_write($socket, $accept ? "\x01" : "\x00", 1);
}

function sendAck($socket, int $count): void
{
    socket_write($socket, pack('N', $count), 4);
}

function readImei($socket): ?string
{
    $data = socket_read($socket, 17, PHP_BINARY_READ);
    if ($data === false || strlen($data) < 2) {
        return null;
    }

    $len  = unpack('n', substr($data, 0, 2))[1];
    $imei = substr($data, 2, $len);

    writeLog("IMEI raw: " . bin2hex($data) . " | parsed: '{$imei}'", 'DEBUG');

    return preg_match('/^\d{15,16}$/', $imei) ? $imei : null;
}

// ─── CODEC 8 PARSER ───────────────────────────────────────────────────────────

function parseCodec8(string $data): array
{
    $offset  = 0;
    $records = [];

    $codecId     = ord($data[$offset]); $offset += 1;
    $recordCount = ord($data[$offset]); $offset += 1;

    for ($i = 0; $i < $recordCount; $i++) {
        if ($offset + 24 > strlen($data)) break;

        // Timestamp (8 bayt, ms)
        $tsHigh    = unpack('N', substr($data, $offset,     4))[1];
        $tsLow     = unpack('N', substr($data, $offset + 4, 4))[1];
        $timestamp = (int)(($tsHigh * 4294967296 + $tsLow) / 1000);
        $offset   += 8;

        $priority = ord($data[$offset]); $offset += 1;

        // Longitude
        $lngRaw = unpack('N', substr($data, $offset, 4))[1];
        if ($lngRaw >= 2147483648) $lngRaw -= 4294967296;
        // Latitude
        $latRaw = unpack('N', substr($data, $offset + 4, 4))[1];
        if ($latRaw >= 2147483648) $latRaw -= 4294967296;

        $longitude = $lngRaw / 10000000.0;
        $latitude  = $latRaw / 10000000.0;
        $offset   += 8;

        $altitude   = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
        $angle      = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;
        $satellites = ord($data[$offset]);                        $offset += 1;
        $speed      = unpack('n', substr($data, $offset, 2))[1]; $offset += 2;

        // IO elementləri
        $ioElements = [];
        $eventIoId  = ord($data[$offset]); $offset += 1;
        $totalIo    = ord($data[$offset]); $offset += 1;

        // 1 bayt IO
        $count = ord($data[$offset]); $offset += 1;
        for ($j = 0; $j < $count; $j++) {
            $id = ord($data[$offset]);
            $v  = ord($data[$offset + 1]);
            $ioElements[$id] = $v;
            $offset += 2;
        }

        // 2 bayt IO
        $count = ord($data[$offset]); $offset += 1;
        for ($j = 0; $j < $count; $j++) {
            $id = ord($data[$offset]);
            $v  = unpack('n', substr($data, $offset + 1, 2))[1];
            $ioElements[$id] = $v;
            $offset += 3;
        }

        // 4 bayt IO
        $count = ord($data[$offset]); $offset += 1;
        for ($j = 0; $j < $count; $j++) {
            $id = ord($data[$offset]);
            $v  = unpack('N', substr($data, $offset + 1, 4))[1];
            $ioElements[$id] = $v;
            $offset += 5;
        }

        // 8 bayt IO
        $count = ord($data[$offset]); $offset += 1;
        for ($j = 0; $j < $count; $j++) {
            $id   = ord($data[$offset]);
            $high = unpack('N', substr($data, $offset + 1, 4))[1];
            $low  = unpack('N', substr($data, $offset + 5, 4))[1];
            $ioElements[$id] = $high * 4294967296 + $low;
            $offset += 9;
        }

        $records[] = [
            'timestamp'   => $timestamp,
            'priority'    => $priority,
            'latitude'    => $latitude,
            'longitude'   => $longitude,
            'altitude'    => $altitude,
            'angle'       => $angle,
            'satellites'  => $satellites,
            'speed'       => $speed,
            'io_elements' => $ioElements,
        ];
    }

    return $records;
}

function parseAvlPacket(string $rawData): ?array
{
    if (strlen($rawData) < 12) return null;

    $offset   = 0;
    $preamble = unpack('N', substr($rawData, $offset, 4))[1]; $offset += 4;

    if ($preamble !== 0) return null;

    $dataLength = unpack('N', substr($rawData, $offset, 4))[1]; $offset += 4;

    if ($dataLength < 2 || strlen($rawData) < $offset + $dataLength + 4) return null;

    $codecId = ord($rawData[$offset]);
    $payload = substr($rawData, $offset, $dataLength);
    $records = [];

    if ($codecId === 0x08) {
        $records = parseCodec8($payload);
    } else {
        writeLog("Dəstəklənməyən Codec: 0x" . dechex($codecId), 'WARN');
        return null;
    }

    $crc = unpack('N', substr($rawData, $offset + $dataLength, 4))[1];

    return ['codec_id' => $codecId, 'records' => $records, 'crc' => $crc];
}

// ─── CLİENT HANDLER (fork prosesində işləyir) ─────────────────────────────────

function handleClient($clientSocket, string $clientIp): void
{
    writeLog("Yeni bağlantı: {$clientIp}");

    // IMEI oxu
    $imei = readImei($clientSocket);
    if ($imei === null) {
        writeLog("Etibarsız IMEI formatı: {$clientIp}", 'WARN');
        sendImeiResponse($clientSocket, false);
        socket_close($clientSocket);
        return;
    }

    // DB bağlantısı qur
    $pdo = dbConnect();
    if ($pdo === null) {
        writeLog("DB bağlantısı yoxdur, bağlantı rədd edildi: IMEI={$imei}", 'ERROR');
        sendImeiResponse($clientSocket, false);
        socket_close($clientSocket);
        return;
    }

    // IMEI-ni DB-də yoxla
    $gpsId = getGpsIdByImei($pdo, $imei);
    if ($gpsId === null) {
        writeLog("IMEI tapılmadı və ya deaktivdir: {$imei}", 'WARN');
        sendImeiResponse($clientSocket, false);
        socket_close($clientSocket);
        return;
    }

    writeLog("IMEI qəbul edildi: {$imei} → gps_id={$gpsId} (IP: {$clientIp})");
    sendImeiResponse($clientSocket, true);

    // Məlumat oxuma dövrü
    while (true) {
        $rawData = '';

        // Preamble (4 bayt)
        $chunk = socket_read($clientSocket, 4, PHP_BINARY_READ);
        if ($chunk === false || $chunk === '') {
            writeLog("Bağlantı kəsildi: IMEI={$imei}", 'INFO');
            break;
        }
        $rawData .= $chunk;

        // Data length (4 bayt)
        $chunk = socket_read($clientSocket, 4, PHP_BINARY_READ);
        if ($chunk === false || strlen($chunk) < 4) break;
        $rawData .= $chunk;

        $dataLength = unpack('N', $chunk)[1];

        if ($dataLength < 2 || $dataLength > 65536) {
            writeLog("Etibarsız data uzunluğu: {$dataLength}", 'WARN');
            break;
        }

        // Əsas data + CRC
        $remaining = $dataLength + 4;
        while ($remaining > 0) {
            $chunk = socket_read($clientSocket, min($remaining, 4096), PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') break 2;
            $rawData   .= $chunk;
            $remaining -= strlen($chunk);
        }

        // Raw log
        writeLog("RAW [{$imei}]: " . bin2hex($rawData), 'RAW', true);

        // Parse
        $parsed = parseAvlPacket($rawData);
        if ($parsed === null) {
            writeLog("Parse xətası: IMEI={$imei}", 'ERROR');
            continue;
        }

        $count = count($parsed['records']);
        writeLog("Qəbul: IMEI={$imei} | Codec=0x" . dechex($parsed['codec_id']) . " | Qeyd={$count}");

        $saved = 0;
        foreach ($parsed['records'] as $record) {
            writeGpsLog($imei, $record);

            try {
                if (saveGpsRecord($pdo, $gpsId, $record)) {
                    $saved++;
                }
            } catch (PDOException $e) {
                writeLog("DB yazma xətası: " . $e->getMessage(), 'ERROR');
            }
        }

        writeLog("DB-yə yazıldı: {$saved}/{$count} qeyd | IMEI={$imei}");

        // ACK göndər
        sendAck($clientSocket, $count);
    }

    socket_close($clientSocket);
    $pdo = null;
    writeLog("Bağlantı bağlandı: IMEI={$imei}");
}

// ─── ANA TCP SERVER (pcntl_fork ilə) ─────────────────────────────────────────

if (!function_exists('pcntl_fork')) {
    writeLog("pcntl extension tapılmadı! Serverdə 'pcntl' aktiv edin.", 'ERROR');
    exit(1);
}

writeLog("Teltonika FMM640 GPS Server başladılır (pcntl_fork, max=" . MAX_CLIENTS . ")...");
writeLog("Dinlənir: " . SERVER_HOST . ":" . SERVER_PORT);
writeLog("Verilənlər bazası: " . DB_HOST . "/" . DB_NAME);

$serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
if ($serverSocket === false) {
    writeLog("Socket yaradılmadı: " . socket_strerror(socket_last_error()), 'ERROR');
    exit(1);
}

socket_set_option($serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

if (!socket_bind($serverSocket, SERVER_HOST, SERVER_PORT)) {
    writeLog("Bind xətası: " . socket_strerror(socket_last_error($serverSocket)), 'ERROR');
    exit(1);
}

if (!socket_listen($serverSocket, MAX_CLIENTS)) {
    writeLog("Listen xətası: " . socket_strerror(socket_last_error($serverSocket)), 'ERROR');
    exit(1);
}

socket_set_nonblock($serverSocket);
writeLog("Server hazırdır. Bağlantı gözlənilir...");

// Zombie prosesləri təmizlə
pcntl_signal(SIGCHLD, function () {
    while (pcntl_waitpid(-1, $status, WNOHANG) > 0) {}
});

$childCount = 0;
$ipConnections = []; // IP basina aktiv baglanti sayi
$pidToIp = [];       // PID → IP xeritesi

while (true) {
    // Zombie prosesleri yig
    pcntl_signal_dispatch();

    $newSocket = @socket_accept($serverSocket);

    if ($newSocket !== false) {
        socket_getpeername($newSocket, $clientIp);

        // ── LIMiT 1: Maksimum umumi proses sayi ──
        if ($childCount >= MAX_PROCESSES) {
            writeLog("Proses limiti ({$childCount}) asildi, redd edildi: {$clientIp}", 'WARN');
            socket_close($newSocket);

        // ── LIMiT 2: Bir IP-den maksimum baglanti sayi ──
        } elseif (($ipConnections[$clientIp] ?? 0) >= MAX_CONN_PER_IP) {
            writeLog("IP limiti asildi ({$clientIp}), redd edildi", 'WARN');
            socket_close($newSocket);

        } else {
            $pid = pcntl_fork();

            if ($pid === -1) {
                writeLog("fork() xetasi!", 'ERROR');
                socket_close($newSocket);

            } elseif ($pid === 0) {
                // ── USAQ PROSES ──
                socket_close($serverSocket);
                handleClient($newSocket, $clientIp);
                exit(0);

            } else {
                // ── ANA PROSES ──
                socket_close($newSocket);
                $childCount++;
                $ipConnections[$clientIp] = ($ipConnections[$clientIp] ?? 0) + 1;
                $pidToIp[$pid] = $clientIp;
                writeLog("Fork edildi: PID={$pid} | Aktiv={$childCount} | IP={$clientIp}({$ipConnections[$clientIp]})");
            }
        }
    }

    // Tamamlanmis usaq prosesleri say
    while (($donePid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
        $childCount = max(0, $childCount - 1);
        // IP sayacini azalt
        if (isset($pidToIp[$donePid])) {
            $doneIp = $pidToIp[$donePid];
            $ipConnections[$doneIp] = max(0, ($ipConnections[$doneIp] ?? 1) - 1);
            if ($ipConnections[$doneIp] === 0) unset($ipConnections[$doneIp]);
            unset($pidToIp[$donePid]);
        }
        writeLog("Proses tamamlandi: PID={$donePid} | Aktiv baglantılar={$childCount}");
    }

    usleep(50000); // 50ms
}

socket_close($serverSocket);
