# Teltonika FMM640 GPS Server - PHP

## Tələblər
- PHP 7.4+
- `ext-sockets` PHP uzantısı aktiv olmalıdır

## İşə salmaq

```bash
php teltonika_fmm640_server.php
```

## Konfiqurasiya (faylın əvvəlindəki sabitlər)

| Sabit         | Default         | Açıqlama                    |
|---------------|-----------------|-----------------------------|
| SERVER_HOST   | 0.0.0.0         | Dinləmə IP                  |
| SERVER_PORT   | 5000            | TCP port                    |
| LOG_DIR       | ./logs          | Log qovluğu                 |
| LOG_FILE      | logs/gps_data.log | GPS məlumatları log faylı |
| RAW_LOG_FILE  | logs/raw_data.log | Xam (HEX) data log faylı  |

## Cihaz tərəfindən konfiqurasiya (Teltonika FMM640)
1. **Server IP** → sizin serverinizin IP ünvanı
2. **Port** → 5000
3. **Protocol** → TCP
4. **Codec** → Codec 8

## Log formatı (gps_data.log)

```
[2024-01-15 10:30:22] [INFO] IMEI qəbul edildi: 352094081234567 (IP: 192.168.1.100)
[2024-01-15 10:30:23] [GPS] IMEI=352094081234567 | RecordTime=2024-01-15 10:30:20 | Lat=40.409264 | Lng=49.867092 | Alt=28m | Angle=135° | Speed=60km/h | Sats=8 | IO[239]=1 | IO[240]=0
```

## IO Element ID-ləri (FMM640 üçün ümumi)

| ID  | Açıqlama              |
|-----|-----------------------|
| 239 | Ignition              |
| 240 | Movement              |
| 21  | GSM Signal            |
| 200 | Sleep Mode            |
| 69  | GNSS Status           |
| 181 | GNSS PDOP             |
| 182 | GNSS HDOP             |

## Produksiya üçün

Çoxlu cihazla işləmək üçün `pcntl_fork()` əlavə edin:

```php
$pid = pcntl_fork();
if ($pid === 0) {
    // Uşaq proses - client idarə edir
    handleClient($newSocket, $clientIp);
    exit(0);
}
```
