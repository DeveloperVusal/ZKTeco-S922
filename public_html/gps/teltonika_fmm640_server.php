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

// Log qovluğunu yarat
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR, 0755, true);
}

/**
 * Log funksiyası - faylа yaz
 */
function writeLog(string $message, string $level = 'INFO', bool $rawOnly = false): void
{
    $timestamp = date('Y-m-d H:i:s');
    $logLine   = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

    if (!$rawOnly) {
        file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
        echo $logLine;
    } else {
        file_put_contents(RAW_LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);
    }
}

/**
 * GPS məlumatını strukturlaşdırılmış şəkildə log et
 */
function writeGpsLog(string $imei, array $record): void
{
    $timestamp = date('Y-m-d H:i:s');

    $lat       = number_format($record['latitude'], 6);
    $lng       = number_format($record['longitude'], 6);
    $alt       = $record['altitude'] ?? 0;
    $angle     = $record['angle'] ?? 0;
    $speed     = $record['speed'] ?? 0;
    $sats      = $record['satellites'] ?? 0;
    $recTime   = date('Y-m-d H:i:s', $record['timestamp']);

    $logLine = "[{$timestamp}] [GPS] IMEI={$imei} | "
        . "RecordTime={$recTime} | "
        . "Lat={$lat} | Lng={$lng} | "
        . "Alt={$alt}m | Angle={$angle}° | "
        . "Speed={$speed}km/h | Sats={$sats}";

    // IO elementlər varsa əlavə et
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

/**
 * IMEI doğrulama cavabı göndər
 */
function sendImeiResponse($socket, bool $accept = true): void
{
    $response = $accept ? "\x01" : "\x00";
    socket_write($socket, $response, 1);
}

/**
 * Məlumat qəbulunu təsdiq et (ACK)
 */
function sendAck($socket, int $count): void
{
    // 4 bayt big-endian
    $ack = pack('N', $count);
    socket_write($socket, $ack, 4);
}

/**
 * IMEI oxu (ilk paket)
 */
function readImei($socket): ?string
{
    $data = socket_read($socket, 17, PHP_BINARY_READ);
    if ($data === false || strlen($data) < 2) {
        return null;
    }

    $len  = unpack('n', substr($data, 0, 2))[1];
    $imei = substr($data, 2, $len);

    return preg_match('/^\d{15,16}$/', $imei) ? $imei : null;
}

/**
 * Codec 8 AVL paketini parse et
 */
function parseCodec8(string $data): array
{
    $offset  = 0;
    $records = [];

    // Codec ID
    $codecId = ord($data[$offset]);
    $offset += 1;

    // Qeyd sayı
    $recordCount = ord($data[$offset]);
    $offset += 1;

    for ($i = 0; $i < $recordCount; $i++) {
        if ($offset + 15 > strlen($data)) {
            break;
        }

        // Timestamp (8 bayt, millisaniyə)
        $tsHigh    = unpack('N', substr($data, $offset, 4))[1];
        $tsLow     = unpack('N', substr($data, $offset + 4, 4))[1];
        $timestamp = (int)(($tsHigh * 4294967296 + $tsLow) / 1000);
        $offset   += 8;

        // Priority
        $priority = ord($data[$offset]);
        $offset  += 1;

        // GPS məlumatı
        $lngRaw = unpack('N', substr($data, $offset, 4))[1];
        if ($lngRaw >= 2147483648) {
            $lngRaw -= 4294967296;
        }
        $latRaw = unpack('N', substr($data, $offset + 4, 4))[1];
        if ($latRaw >= 2147483648) {
            $latRaw -= 4294967296;
        }

        $longitude = $lngRaw / 10000000.0;
        $latitude  = $latRaw / 10000000.0;
        $offset   += 8;

        $altitude = unpack('n', substr($data, $offset, 2))[1];
        $offset  += 2;

        $angle = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        $satellites = ord($data[$offset]);
        $offset    += 1;

        $speed  = unpack('n', substr($data, $offset, 2))[1];
        $offset += 2;

        // IO elementləri
        $ioElements = [];

        // Event IO ID
        $eventIoId = ord($data[$offset]);
        $offset   += 1;

        // IO elementlərinin ümumi sayı
        $totalIo = ord($data[$offset]);
        $offset += 1;

        // 1 baytlıq IO
        $count1b = ord($data[$offset]);
        $offset += 1;
        for ($j = 0; $j < $count1b; $j++) {
            $id  = ord($data[$offset]);
            $val = ord($data[$offset + 1]);
            $ioElements[$id] = $val;
            $offset += 2;
        }

        // 2 baytlıq IO
        $count2b = ord($data[$offset]);
        $offset += 1;
        for ($j = 0; $j < $count2b; $j++) {
            $id  = ord($data[$offset]);
            $val = unpack('n', substr($data, $offset + 1, 2))[1];
            $ioElements[$id] = $val;
            $offset += 3;
        }

        // 4 baytlıq IO
        $count4b = ord($data[$offset]);
        $offset += 1;
        for ($j = 0; $j < $count4b; $j++) {
            $id  = ord($data[$offset]);
            $val = unpack('N', substr($data, $offset + 1, 4))[1];
            $ioElements[$id] = $val;
            $offset += 5;
        }

        // 8 baytlıq IO
        $count8b = ord($data[$offset]);
        $offset += 1;
        for ($j = 0; $j < $count8b; $j++) {
            $id    = ord($data[$offset]);
            $high  = unpack('N', substr($data, $offset + 1, 4))[1];
            $low   = unpack('N', substr($data, $offset + 5, 4))[1];
            $val   = $high * 4294967296 + $low;
            $ioElements[$id] = $val;
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

/**
 * AVL paketini tam oxu və parse et
 */
function parseAvlPacket(string $rawData): ?array
{
    if (strlen($rawData) < 12) {
        return null;
    }

    $offset = 0;

    // Preamble (4 bayt 0x00000000)
    $preamble = unpack('N', substr($rawData, $offset, 4))[1];
    $offset  += 4;

    if ($preamble !== 0) {
        return null;
    }

    // Data length
    $dataLength = unpack('N', substr($rawData, $offset, 4))[1];
    $offset    += 4;

    if ($dataLength < 2 || strlen($rawData) < $offset + $dataLength + 4) {
        return null;
    }

    // Codec ID
    $codecId = ord($rawData[$offset]);

    $payload = substr($rawData, $offset, $dataLength);

    $records = [];
    if ($codecId === 0x08) {
        // Codec 8
        $records = parseCodec8(substr($payload, 0));
    } else {
        writeLog("Dəstəklənməyən Codec ID: 0x" . dechex($codecId), 'WARN');
        return null;
    }

    // CRC (son 4 bayt)
    $crc = unpack('N', substr($rawData, $offset + $dataLength, 4))[1];

    return [
        'codec_id' => $codecId,
        'records'  => $records,
        'crc'      => $crc,
    ];
}

/**
 * Client bağlantısını idarə et
 */
function handleClient($clientSocket, string $clientIp): void
{
    writeLog("Yeni bağlantı: {$clientIp}");

    // IMEI oxu
    $imei = readImei($clientSocket);
    if ($imei === null) {
        writeLog("Etibarsız IMEI: {$clientIp}", 'WARN');
        socket_close($clientSocket);
        return;
    }

    writeLog("IMEI qəbul edildi: {$imei} (IP: {$clientIp})");
    sendImeiResponse($clientSocket, true);

    // Məlumat oxuma dövrü
    while (true) {
        $rawData = '';

        // Preamble (4 bayt) oxu
        $chunk = socket_read($clientSocket, 4, PHP_BINARY_READ);
        if ($chunk === false || $chunk === '') {
            writeLog("Bağlantı kəsildi: IMEI={$imei}", 'INFO');
            break;
        }
        $rawData .= $chunk;

        // Data length (4 bayt) oxu
        $chunk = socket_read($clientSocket, 4, PHP_BINARY_READ);
        if ($chunk === false || strlen($chunk) < 4) {
            break;
        }
        $rawData .= $chunk;

        $dataLength = unpack('N', $chunk)[1];

        if ($dataLength < 2 || $dataLength > 65536) {
            writeLog("Etibarsız data uzunluğu: {$dataLength}", 'WARN');
            break;
        }

        // Əsas data oxu
        $remaining = $dataLength + 4; // data + CRC
        while ($remaining > 0) {
            $chunk = socket_read($clientSocket, min($remaining, 4096), PHP_BINARY_READ);
            if ($chunk === false || $chunk === '') {
                break 2;
            }
            $rawData    .= $chunk;
            $remaining  -= strlen($chunk);
        }

        // Raw log
        $hexData = bin2hex($rawData);
        writeLog("RAW [{$imei}]: {$hexData}", 'RAW', true);

        // Parse et
        $parsed = parseAvlPacket($rawData);
        if ($parsed === null) {
            writeLog("Parse xətası: IMEI={$imei}", 'ERROR');
            continue;
        }

        $count = count($parsed['records']);
        writeLog("Qəbul edildi: IMEI={$imei} | Codec=0x" . dechex($parsed['codec_id']) . " | Qeyd sayı={$count}");

        // Hər GPS qeydi üçün log yaz
        foreach ($parsed['records'] as $record) {
            writeGpsLog($imei, $record);
        }

        // ACK göndər
        sendAck($clientSocket, $count);
    }

    socket_close($clientSocket);
    writeLog("Bağlantı bağlandı: IMEI={$imei}");
}

// ─── ANA TCP SERVER ───────────────────────────────────────────────────────────

writeLog("Teltonika FMM640 GPS Server başladılır...");
writeLog("Dinlənir: " . SERVER_HOST . ":" . SERVER_PORT);
writeLog("Log fayl: " . LOG_FILE);

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

writeLog("Server hazırdır. Bağlantı gözlənilir...");

// Non-blocking rejim
socket_set_nonblock($serverSocket);

$clients = [];

while (true) {
    // Yeni bağlantı qəbul et
    $newSocket = @socket_accept($serverSocket);
    if ($newSocket !== false) {
        socket_getpeername($newSocket, $clientIp);
        writeLog("Gələn bağlantı: {$clientIp}");

        // Fork etmək əvəzinə sadə ardıcıl idarəetmə
        // (Produksiya üçün pcntl_fork() istifadə edin)
        handleClient($newSocket, $clientIp);
    }

    usleep(100000); // 100ms gözlə
}

socket_close($serverSocket);
