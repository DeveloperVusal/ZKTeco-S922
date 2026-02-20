<?php
// db.php
declare(strict_types=1);

$dbHost = "localhost";
$dbName = "u879108216_iclock";
$dbUser = "u879108216_iclock";
$dbPass = "@9XeD5=9/Pp";

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo "DB error: " . htmlspecialchars($e->getMessage());
    exit;
}
