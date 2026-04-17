# Teltonika FMM640 GPS Server — PHP

Teltonika FMM640 GPS treker cihazlarından məlumat qəbul edən TCP serveri.  
**Codec 8**, `pcntl_fork` ilə çoxproseslilik və **MySQL** dəstəyi mövcuddur.

---

## Tələblər

| Komponent | Versiya |
|-----------|---------|
| PHP | 7.4+ |
| MySQL / MariaDB | 5.7+ / 10.3+ |
| PHP genişləndirmələri | `ext-sockets`, `ext-pcntl`, `ext-pdo_mysql` |

Genişləndirmələrin mövcudluğunu yoxlamaq:
```bash
php -m | grep -E "sockets|pcntl|pdo_mysql"
```

---

## Quraşdırma və işə salma

```bash
# Serveri işə sal
php teltonika_fmm640_server.php

# Arxa planda işə sal (loqla birlikdə)
nohup php teltonika_fmm640_server.php >> logs/server.log 2>&1 &

# Serverin portu dinlədiyini yoxla
ss -tlnp | grep 5000
```

---

## Konfiqurasiya

Parametrlər faylın əvvəlindəki sabitlərlə təyin edilir:

| Sabit | Standart dəyər | Açıqlama |
|-------|---------------|----------|
| `SERVER_HOST` | `0.0.0.0` | Dinləmə IP ünvanı |
| `SERVER_PORT` | `5000` | TCP port |
| `LOG_DIR` | `./logs` | Loq qovluğu |
| `LOG_FILE` | `logs/gps_data.log` | GPS məlumatları loq faylı |
| `RAW_LOG_FILE` | `logs/raw_data.log` | Xam HEX data loq faylı |
| `DB_HOST` | `127.0.0.1` | MySQL host |
| `DB_PORT` | `3306` | MySQL port |
| `DB_NAME` | `your_database` | Verilənlər bazasının adı |
| `DB_USER` | `your_user` | VB istifadəçisi |
| `DB_PASS` | `your_password` | VB şifrəsi |

---

## Verilənlər bazası

### Cihazlar cədvəli `fngr_pl_gps`
Qeydiyyatdan keçmiş GPS trekerləri saxlayır. Yeni IMEI ilk dəfə qoşulduqda — cihaz **avtomatik qeydiyyatdan keçir** (`status=1`).

```sql
CREATE TABLE fngr_pl_gps (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uuid        VARCHAR(36)      DEFAULT (uuid()) NULL,
    imei        VARCHAR(80)                       NULL,
    `key`       VARCHAR(80)                       NULL,
    status      TINYINT UNSIGNED DEFAULT '0'      NULL,
    description VARCHAR(50)                       NULL,
    CONSTRAINT imei_UNIQUE UNIQUE (imei),
    CONSTRAINT key_UNIQUE  UNIQUE (`key`)
);
```

### GPS qeydlər cədvəli `gps_records`
Bütün qəbul edilmiş telemetriya məlumatlarını saxlayır.

```sql
CREATE TABLE gps_records (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gps_id      INT                                    NULL,  -- fngr_pl_gps.id
    record_time DATETIME                               NOT NULL,
    lat         DECIMAL(10, 6)                         NULL,
    lng         DECIMAL(10, 6)                         NULL,
    altitude    INT                                    NULL,
    angle       SMALLINT                               NULL,
    speed       SMALLINT                               NULL,
    sats        SMALLINT                               NULL,
    io_22       INT                                    NULL,
    io_71       INT                                    NULL,
    io_240      INT                                    NULL,
    io_21       INT                                    NULL,
    io_200      INT                                    NULL,
    io_239      INT                                    NULL,
    io_67       INT                                    NULL,
    io_68       INT                                    NULL,
    io_181      INT                                    NULL,
    io_182      INT                                    NULL,
    io_66       INT                                    NULL,
    io_24       INT                                    NULL,
    io_201      INT                                    NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP    NULL,
    status      TINYINT UNSIGNED DEFAULT '1'           NULL
);
```

---

## FMM640 Cihazının konfiqurasiyası

**Teltonika Configurator** vasitəsilə (USB ilə qoşun):

```
System → Server Settings:
  Server IP/Domain : SİZİN_SERVER_IP
  Port             : 5000
  Protocol         : TCP
  Codec            : Codec 8
```

### Yeniləmə tezliyi (Data Acquisition)

| Parametr | Açıqlama | Tövsiyə (real vaxt) |
|----------|----------|---------------------|
| `Min Period` | Qeydlər arasındakı interval (san) | 30 |
| `Min Distance` | Yeni qeyd üçün min. məsafə (m) | 100 |
| `Min Angle` | Min. dönüş bucağı | 15° |
| `Min Saved Records` | Göndərməzdən əvvəl yığılacaq qeyd sayı | 1 |
| `Send Period` | Hər N saniyədən bir məcburi göndərmə | 30 |

> **Home / Roaming / Unknown** — SIM kartın şəbəkə növündən asılı olaraq üç ayrı profil.  
> Roaminqdə trafiki qənaət etmək üçün böyük dəyərlər tövsiyə edilir.

---

## Server arxitekturası

```
Ana proses (5000 portu dinləyir)
    │
    ├── pcntl_fork() → Uşaq proses [Treker #1, IMEI=860536...]
    ├── pcntl_fork() → Uşaq proses [Treker #2, IMEI=352094...]
    ├── pcntl_fork() → Uşaq proses [Treker #3, IMEI=...]
    └── ...
```

Hər treker **ayrıca proses** tərəfindən idarə olunur. Zombie proseslər `SIGCHLD` siqnalı vasitəsilə avtomatik təmizlənir.

### Bağlantının həyat dövrü

```
Treker qoşulur
    ↓
IMEI oxunur (ilk paket)
    ↓
fngr_pl_gps-də IMEI axtarılır
    ↓ tapılmadı → avtomatik qeydiyyat (status=1)
    ↓ tapıldı   → id götürülür
    ↓
Təsdiq göndərilir \x01
    ↓
AVL paket qəbul dövrü
    ↓
Codec 8 parse → gps_records-a yazılır
    ↓
ACK göndərilir (qeyd sayı)
```

---

## IO elementləri (FMM640)

| IO ID | Açıqlama | Dəyərlər |
|-------|----------|----------|
| `239` | Yandırma (Ignition) | 0 = söndürülüb, 1 = yandırılıb |
| `240` | Hərəkət (Movement) | 0 = dayanıb, 1 = hərəkətdədir |
| `21` | GSM Siqnalı | 0–5 |
| `200` | Yuxu rejimi (Sleep Mode) | 0 = aktivdir |
| `66` | Xarici gərginlik (mV) | 1000-ə bölün = Volt |
| `67` | Batareya gərginliyi (mV) | 1000-ə bölün = Volt |
| `68` | Batareya cərəyanı | — |
| `71` | Dallas Temperaturu | °C |
| `181` | GNSS PDOP | mövqe dəqiqliyi |
| `182` | GNSS HDOP | üfüqi dəqiqlik |
| `24` | Sürət (km/saat) | — |
| `22` | Ox sürəti | — |
| `201` | LVC (Aşağı gərginlik kəsimi) | — |

---

## Loq formatı

**gps_data.log** — strukturlaşdırılmış GPS məlumatları:
```
[2026-03-12 15:20:37] [PID:12345] [INFO] IMEI qəbul edildi: 860536049302096 → gps_id=1 (IP: 217.25.27.162)
[2026-03-12 15:20:37] [PID:12345] [GPS]  IMEI=860536049302096 | RecordTime=2026-03-12 15:20:29 | Lat=40.379921 | Lng=49.875703 | Alt=1m | Angle=0° | Speed=0km/h | Sats=12 | IO[239]=0 | IO[240]=0 | IO[67]=9671
```

**raw_data.log** — xam HEX paketlər (debug üçün):
```
[2026-03-12 15:20:37] [PID:12345] [RAW] RAW [860536049302096]: 000000000000004208010000019ce1c6d0d4...
```

---

## Monitorinq

```bash
# GPS məlumatlarını real vaxtda izlə
tail -f logs/gps_data.log

# Xam paketləri izlə
tail -f logs/raw_data.log

# Aktiv treker proseslərini yoxla
ps aux | grep php

# VB-də qoşulmuş trekerləri yoxla
mysql -u user -p -e "SELECT id, imei, description, status FROM fngr_pl_gps;"

# Son 10 qeydi göstər
mysql -u user -p -e "SELECT gps_id, record_time, lat, lng, speed, sats FROM gps_records ORDER BY id DESC LIMIT 10;"
```

---

## Cihaz olmadan test

Trekeri əl ilə simulyasiya etmək:

```bash
python3 -c "
import socket, time
s = socket.socket()
s.connect(('127.0.0.1', 5000))

imei = b'860536049302096'
packet = len(imei).to_bytes(2, 'big') + imei
s.send(packet)

resp = s.recv(1)
print('Cavab:', resp.hex())  # Gözlənilən: 01
time.sleep(2)
s.close()
"
```

Gözlənilən cavab `01` — server IMEI-ni qəbul etdi.

---

## Mümkün xətalar

| Loqdakı xəta | Səbəb | Həll |
|--------------|-------|------|
| `pcntl extension tapılmadı` | pcntl genişləndirilməsi yoxdur | php.ini-də aktivləşdirin: `extension=pcntl` |
| `DB bağlantı xətası` | Yanlış VB məlumatları | DB_* sabitlərini yoxlayın |
| `Etibarsız IMEI formatı` | Treker IMEI əvəzinə başqa məlumat göndərir | Konfiquratorda Protocol-u yoxlayın |
| `Bind xətası` | Port artıq istifadədədir | `lsof -i :5000` → köhnə prosesi dayandırın |
| `Parse xətası` | Naməlum Codec | Konfiquratorda Codec 8 seçin |