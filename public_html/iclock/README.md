# ZKTeco S922 — API İnteqrasiya Sənədləşməsi

> Bu sənəd ZKTeco S922 cihazını öz API serverinizə qoşmaq istəyən tərtibatçılar üçün hazırlanmışdır.

---

## Mündəricat

1. [Ümumi Məlumat](#ümumi-məlumat)
2. [Necə İşləyir](#necə-işləyir)
3. [Server Tərəfindən Tətbiq Edilməli Endpointlər](#endpointlər)
4. [Qeydiyyat Parametrləri](#qeydiyyat-parametrləri)
5. [Məlumat Formatları](#məlumat-formatları)
6. [Heartbeat və Komanda Növbəsi](#heartbeat-və-komanda-növbəsi)
7. [Cihazdan İstifadəçiləri Almaq](#cihazdan-istifadəçiləri-almaq)
8. [Laravel Nümunəsi](#laravel-nümunəsi)
9. [Tez-tez Rast Gəlinən Problemlər](#tez-tez-rast-gəlinən-problemlər)

---

## Ümumi Məlumat

ZKTeco S922 **ADMS (Push) protokolundan** istifadə edir. Bu o deməkdir ki:

- Cihaz **özü** serverə sorğu göndərir (server cihaza yox)
- Server **passiv** mövqedə dayanır və cihazın sorğularını qəbul edir
- Cihaza komanda göndərmək üçün **heartbeat cavabından** istifadə olunur

> ⚠️ Cihaz mobil internet ilə işlədikdə server mütləq **public IP** və ya **domain** üzərindən əlçatan olmalıdır. `192.168.x.x` və ya `localhost` işləməyəcək.

---

## Necə İşləyir

```
1. Cihaz işə düşür
        ↓
2. GET /iclock/cdata  →  Server qeydiyyat parametrlərini qaytarır
        ↓
3. Cihaz hər verifikasiyadan sonra POST /iclock/cdata  →  Davamiyyat məlumatı
        ↓
4. Cihaz hər ~10 saniyədə GET /iclock/getrequest  →  "Komanda varmı?"
        ↓
5. Server "OK" və ya komanda qaytarır
```

---

## Endpointlər

Serverinizdə aşağıdakı 3 endpoint mütləq mövcud olmalıdır:

| Method | Path | Təyinat |
|--------|------|---------|
| `GET` | `/iclock/cdata` | Cihazın ilkin qeydiyyatı |
| `POST` | `/iclock/cdata` | Cihazdan məlumat qəbulu (davamiyyat, istifadəçilər) |
| `GET` | `/iclock/getrequest` | Heartbeat — cihaz komanda sorğusu edir |

> ⚠️ Bütün cavablar `Content-Type: text/plain` və HTTP `200` statusu ilə qaytarılmalıdır. JSON işləmir!

---

## Qeydiyyat Parametrləri

`GET /iclock/cdata` sorğusuna cavab olaraq server aşağıdakı formatda mətn qaytarmalıdır:

```
GET OK
ATTLOGStamp=9999
OPERLOGStamp=9999
ErrorDelay=60
Delay=1
TransTimes=00:00;14:00
TransInterval=1
TransFlag=TransData AttLog OpLog
Realtime=1
Encrypt=0
```

### Parametrlərin İzahı

| Parametr | Dəyər | İzah |
|----------|-------|------|
| `ATTLOGStamp` | `9999` | Sonuncu sinxronizasiya markası. `9999` — bütün qeydləri göndər |
| `OPERLOGStamp` | `9999` | Sistem jurnalı üçün eyni məntiq |
| `ErrorDelay` | `60` | Xəta zamanı növbəti cəhddən əvvəl gözləmə (saniyə) |
| `Delay` | `1` | Verifikasiyadan sonra göndərməyə qədər gözləmə (saniyə). `1` = demək olar ani |
| `TransTimes` | `00:00;14:00` | Planlı sinxronizasiya saatları |
| `TransInterval` | `1` | Planlı göndərmə intervalı (dəqiqə) |
| `TransFlag` | `TransData AttLog OpLog` | Hansı məlumat növlərinin göndəriləcəyi |
| `Realtime` | `1` | `1` = hər verifikasiyadan dərhal sonra göndər |
| `Encrypt` | `0` | `0` = şifrələmə yoxdur (HTTP), `1` = şifrəli |

---

## Məlumat Formatları

### ATTLOG — Davamiyyat Qeydi

Cihaz hər uğurlu verifikasiyadan sonra `POST /iclock/cdata?table=ATTLOG` göndərir.

**Nümunə:**
```
4	2026-03-11 20:56:36	0	1	0	0	0	0	0	0
```

Sahələr `TAB (\t)` ilə ayrılır:

| Sıra | Sahə | Nümunə | İzah |
|------|------|---------|------|
| 0 | `user_id` | `4` | İstifadəçinin PIN kodu |
| 1 | `event_time` | `2026-03-11 20:56:36` | Verifikasiya vaxtı (cihaz vaxtı) |
| 2 | `verify_type` | `0` | Verifikasiya üsulu (aşağıya bax) |
| 3 | `in_out_status` | `1` | Giriş/Çıxış statusu (aşağıya bax) |
| 4-9 | `col4`-`col9` | `0` | Əlavə sahələr |

**verify_type dəyərləri:**

| Dəyər | Mənası |
|-------|--------|
| `0` | Barmaq izi |
| `1` | Şifrə |
| `4` | Kart |
| `15` | Üz tanıma |

**in_out_status dəyərləri:**

| Dəyər | Mənası |
|-------|--------|
| `0` | Giriş |
| `1` | Çıxış |
| `2` | Fasilə (çıxış) |
| `3` | Fasilə (qayıdış) |
| `4` | Əlavə iş (başlanğıc) |
| `5` | Əlavə iş (son) |

---

### OPLOG — Sistem Jurnalı

Sistem hadisələri üçün. `user_id` sıfır ola bilər — bu normdur.

**Nümunə:**
```
OPLOG 6	0	2026-03-11 20:56:28	4	0	0	0
```

> OPLOG davamiyyat uçotu üçün deyil, **diaqnostika** məqsədilə istifadə olunur. Əsas məlumata ATTLOG-dan güvənin.

---

## Heartbeat və Komanda Növbəsi

Cihaz hər ~10 saniyədə `GET /iclock/getrequest` sorğusu göndərir.

**Komanda yoxdursa:**
```
OK
```

**Komanda varsa:**
```
C:1:DATA QUERY tableName=user
```

### ⚠️ Vacib Qayda

Heartbeat cavabında **həmişə eyni komandanı qaytarmayın** — cihaz onu hər 10 saniyədə bir yerinə yetirəcək. Komanda növbəsi istifadə edin:

```php
// Komanda növbəsindən götür
$command = DeviceCommand::where('device_sn', $deviceSN)
    ->where('executed', false)
    ->orderBy('id')
    ->first();

if ($command) {
    $command->update(['executed' => true]); // yerinə yetirilib kimi işarələ
    return response($command->command . "\n", 200)
        ->header('Content-Type', 'text/plain');
}

return response("OK\n", 200)->header('Content-Type', 'text/plain');
```

### Komanda Növbəsi Cədvəli (Migration)

```php
Schema::create('device_commands', function (Blueprint $table) {
    $table->id();
    $table->string('device_sn');
    $table->text('command');
    $table->boolean('executed')->default(false);
    $table->timestamps();
});
```

---

## Cihazdan İstifadəçiləri Almaq

Komanda növbəsinə aşağıdakı qeydi əlavə edin:

```php
DeviceCommand::create([
    'device_sn' => 'COCJ232360001',
    'command'   => 'C:1:DATA QUERY tableName=user',
    'executed'  => false,
]);
```

Cihaz növbəti heartbeat-də bu komandanı alacaq və `POST /iclock/cdata?table=user` ilə istifadəçiləri göndərəcək.

**İstifadəçi məlumatı formatı:**
```
PIN=4	Name=Əli	Pri=0	Passwd=	Card=0	Grp=1	Verify=0
```

---

## Laravel Nümunəsi

### Route Qeydiyyatı

```php
// routes/web.php  (api.php yox! — /api/ prefiksi olmayacaq)
Route::get('/iclock/cdata',       [FingerPrintController::class, 'cdataGet']);
Route::post('/iclock/cdata',      [FingerPrintController::class, 'cdata']);
Route::get('/iclock/getrequest',  [FingerPrintController::class, 'getrequest']);
```

### CSRF İstisna

```php
// app/Http/Middleware/VerifyCsrfToken.php
protected $except = [
    '/iclock/*',
];
```

### Controller

```php
// GET /iclock/cdata — cihazın qeydiyyatı
public function cdataGet(Request $request)
{
    $response  = "GET OK\n";
    $response .= "ATTLOGStamp=9999\n";
    $response .= "OPERLOGStamp=9999\n";
    $response .= "ErrorDelay=60\n";
    $response .= "Delay=1\n";
    $response .= "TransTimes=00:00;14:00\n";
    $response .= "TransInterval=1\n";
    $response .= "TransFlag=TransData AttLog OpLog\n";
    $response .= "Realtime=1\n";
    $response .= "Encrypt=0\n";

    return response($response, 200)
        ->header('Content-Type', 'text/plain');
}

// GET /iclock/getrequest — heartbeat
public function getrequest(Request $request)
{
    $deviceSN = $request->query('SN', 'UNKNOWN');

    $command = DeviceCommand::where('device_sn', $deviceSN)
        ->where('executed', false)
        ->orderBy('id')
        ->first();

    if ($command) {
        $command->update(['executed' => true]);
        return response($command->command . "\n", 200)
            ->header('Content-Type', 'text/plain');
    }

    return response("OK\n", 200)
        ->header('Content-Type', 'text/plain');
}

// POST /iclock/cdata — məlumat qəbulu
public function cdata(Request $request)
{
    $table    = $request->query('table', '');
    $deviceSN = $request->query('SN', 'UNKNOWN');
    $rawData  = $request->getContent();

    // İstifadəçilər
    if ($table === 'user') {
        $lines = preg_split("/\r\n|\n|\r/", trim($rawData));
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            $fields = [];
            foreach (explode("\t", $line) as $pair) {
                [$key, $val] = array_pad(explode('=', $pair, 2), 2, null);
                $fields[trim($key)] = trim($val);
            }

            DeviceUser::updateOrCreate(
                ['device_sn' => $deviceSN, 'pin' => $fields['PIN'] ?? null],
                ['name' => $fields['Name'] ?? null, 'card' => $fields['Card'] ?? null]
            );
        }

        return response("OK\n", 200)->header('Content-Type', 'text/plain');
    }

    // Davamiyyat və sistem qeydləri
    $lines = preg_split("/\r\n|\n|\r/", trim($rawData));
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // OPLOG
        if (stripos($line, 'OPLOG') === 0) {
            Oplogs::create([
                'device_sn'   => $deviceSN,
                'raw_payload' => $line,
            ]);
            continue;
        }

        // ATTLOG
        $parts = preg_split("/\t+/", $line);
        AccessLogs::create([
            'device_sn'     => $deviceSN,
            'user_id'       => $parts[0] ?? null,
            'event_time'    => $parts[1] ?? null,
            'verify_type'   => $parts[2] ?? null,
            'in_out_status' => $parts[3] ?? null,
            'raw_payload'   => $line,
        ]);
    }

    return response("OK\n", 200)->header('Content-Type', 'text/plain');
}
```

---

## Tez-tez Rast Gəlinən Problemlər

### 🔴 Qırmızı ikonka sönmür
- Server public IP/domain ilə əlçatandırmı? `localhost` işləmir
- `/iclock/cdata` GET düzgün cavab qaytarırmı? `text/plain` + `GET OK` ilə başlamalıdır
- Firewall/cloud provider portunu açmısınız?

### 📭 Məlumatlar gəlmir
- Cihazda ən azı bir verifikasiya etdinizmi? Məlumat öz-özünə göndərilmir
- `TransFlag` içində `AttLog` varmı?
- `Realtime=1` qoyulubmu?

### ♻️ Eyni məlumat dəfələrlə gəlir
- `ATTLOGStamp` düzgün idarə edilmirmi? `9999` həmişə bütün qeydləri göndərir
- Heartbeat cavabında komanda həmişə təkrarlanırmı? Komanda növbəsindən istifadə edin

### ⏱️ Məlumatlar gec gəlir
- `Realtime=0` deyilmi? `1` edin
- `Delay=10` çoxdur — `1` edin
- `TransInterval` dəyərini azaldın

### 🕐 Vaxt fərqi var
- Cihaz öz lokal vaxtını göndərir, timezone məlumatı olmur
- Cihaz vaxtını serverinizin timezone-u ilə uyğunlaşdırın: **Menyu → System → Date/Time**

---

> Sənəd ZKTeco S922 ADMS Push Protokolu əsasında hazırlanmışdır.
> Digər ZKTeco modellərində əsas məntiq eynidir, lakin bəzi parametrlər fərqli ola bilər.