# Apa itu Redis, dan kenapa ia penting untuk Laravel?

- [Apa itu Redis?](#apa-itu-redis)
- [Redis vs MariaDB](#redis-vs-mariadb)
- [Jenis data dalam Redis](#jenis-data-dalam-redis)
- [Kenapa Redis penting untuk Laravel](#kenapa-redis-penting-untuk-laravel)
- [Bagaimana Redis digunakan dalam Job (langkah demi langkah)](#bagaimana-redis-digunakan-dalam-job-langkah-demi-langkah)
- [Bandingkan driver: file vs database vs redis](#bandingkan-driver-file-vs-database-vs-redis)
- [Bila Redis bukan pilihan yang betul](#bila-redis-bukan-pilihan-yang-betul)
- [Redis dalam projek ini](#redis-dalam-projek-ini)

---

## Apa itu Redis?
**Redis** (REmote DIctionary Server) ialah database yang simpan data dalam **memory (RAM)**, bukan dalam disk.
Ia simpan data sebagai pasangan **key → value**, seperti array PHP yang besar dan dikongsi oleh semua server.

```
key                                   value
──────────────────────────────────    ─────────────────────────
laravel-database-laravel-cache-x  →   "hello redis"
laravel-database-queues:default   →   [job1, job2, job3]        (list)
laravel-database-<session-id>     →   "data session user"
```

Ciri utama:

| Ciri | Maksud |
| --- | --- |
| **Dalam memory** | Baca/tulis dalam **mikrosaat**. Disk (MariaDB) biasanya **milisaat**, iaitu ratusan kali lebih lambat. |
| **Key → value** | Tiada table, tiada column, tiada SQL. Cari terus ikut nama key. |
| **Expiry (TTL)** | Setiap key boleh ada masa tamat: "padam key ini selepas 60 saat". Sangat sesuai untuk cache dan session. |
| **Atomic** | Redis jalankan satu command pada satu masa. Dua request yang `INCR` key yang sama tidak akan bertembung. |
| **Persistence (pilihan)** | Data boleh disimpan ke disk (snapshot RDB / log AOF), supaya tidak hilang bila Redis restart. |
| **Dikongsi** | Semua container/server (`app`, `queue`, server ke-2, ke-3...) sambung ke Redis yang sama dan nampak data yang sama. |

Analogi mudah:
- **MariaDB** = **almari fail** di stor. Simpan semua rekod dengan teratur dan kekal, tetapi perlu masa untuk cari.
- **Redis** = **papan putih di depan meja**. Sangat cepat untuk tulis dan baca, sesuai untuk perkara sementara yang selalu digunakan.

---

## Redis vs MariaDB
Redis **bukan** pengganti MariaDB. Kedua-duanya bekerja bersama.

| | MariaDB / MySQL | Redis |
| --- | --- | --- |
| Simpan di | Disk | Memory (RAM) |
| Kelajuan | Laju | **Sangat** laju |
| Struktur | Table, column, relationship | Key → value (string, list, set, hash...) |
| Query | SQL (`WHERE`, `JOIN`, `ORDER BY`) | Command ringkas (`GET`, `SET`, `LPUSH`...) |
| Saiz data | Besar (GB–TB) | Terhad oleh RAM |
| Sesuai untuk | Data **kekal** dan penting: users, orders, projects | Data **sementara** dan **kerap diakses**: cache, session, queue, counter |
| Dalam Laravel | Eloquent models | Cache, Session, Queue, Rate limiting, Lock |

> **Peraturan mudah:** jika data hilang dan anda **rugi**, simpan dalam MariaDB. Jika data hilang dan app boleh **cipta semula** atau ia memang sementara, Redis sesuai.

---

## Jenis data dalam Redis
Redis bukan sekadar string. Ia ada struktur data, dan Laravel guna beberapa daripadanya:

| Jenis | Contoh command | Laravel guna untuk |
| --- | --- | --- |
| **String** | `SET`, `GET`, `INCR`, `EXPIRE` | Cache value, session, counter rate limit |
| **List** | `RPUSH`, `LPOP` | **Queue**: job ditolak ke hujung, worker ambil dari depan (first in, first out) |
| **Sorted set** | `ZADD`, `ZRANGEBYSCORE` | Job **delay** (score = masa job patut dijalankan), dan job yang sedang diproses |
| **Hash** | `HSET`, `HGET` | Objek kecil dengan banyak field |
| **Pub/Sub** | `PUBLISH`, `SUBSCRIBE` | Broadcasting (event real-time) |

Cuba sendiri:
```bash
docker compose exec redis redis-cli
> SET greeting "hello"
> GET greeting                 # "hello"
> SET temp "bye" EX 10         # tamat dalam 10 saat
> TTL temp                     # baki saat
> INCR visits                  # 1, 2, 3... (atomic)
> RPUSH jobs "a" "b" "c"
> LPOP jobs                    # "a"  (masuk dulu, keluar dulu)
> KEYS *
> exit
```

---

## Kenapa Redis penting untuk Laravel
Laravel ada beberapa komponen yang perlukan tempat simpan **sementara**, **laju**, dan **dikongsi**. Redis sesuai untuk semuanya.

### 1. Cache
Simpan hasil kerja yang berat supaya tidak perlu diulang:
```php
$stats = Cache::remember('dashboard-stats', now()->addMinutes(10), function () {
    return [
        'users' => User::count(),
        'projects' => Project::withCount('users')->get(),
    ];   // query berat, jalan sekali setiap 10 minit sahaja
});
```
- Request pertama: query MariaDB, dan simpan hasil dalam Redis.
- Request seterusnya (10 minit): ambil terus dari Redis, **tanpa** sentuh MariaDB.
- Hasilnya page lebih laju, dan beban database berkurang.

### 2. Session
Setiap kali user buka page, Laravel **baca** session (siapa yang login, CSRF token, flash message seperti `session('status')`).
Ini berlaku pada **setiap request**, jadi ia mesti laju.

| Driver session | Masalah |
| --- | --- |
| `file` | Session disimpan dalam folder server. Jika ada 2 server, user login di server A tetapi request seterusnya ke server B, dan B tidak kenal user itu. |
| `database` | Setiap request = query tambahan ke MariaDB. |
| `redis` | Laju (memory), dikongsi semua server, dan session lama dipadam automatik (TTL). |

### 3. Queue (paling penting dalam tutorial [JobAndMail.md](JobAndMail.md))
Job perlu disimpan di tempat yang boleh dicapai oleh **dua process berbeza**:
```
container app  ──RPUSH job──►  Redis (list queues:default)  ──LPOP job──►  container queue (worker)
```
- `app` dan `queue` ialah container berbeza. Mereka **tidak** berkongsi memory PHP, jadi perlu tempat tengah.
- Redis list memang sesuai untuk "barisan": masuk di hujung, keluar di depan.
- Worker boleh tunggu job baru dengan cekap. Tidak perlu query database setiap saat.
- Banyak worker (`--scale queue=2`) boleh ambil dari list yang sama tanpa ambil job yang sama dua kali (atomic).
- Job **delay** guna sorted set, jadi Redis tahu job mana yang sudah tiba masanya.

Perincian penuh: [Bagaimana Redis digunakan dalam Job](#bagaimana-redis-digunakan-dalam-job-langkah-demi-langkah).

### 4. Rate limiting
Hadkan bilangan request, contohnya "5 cubaan login setiap minit":
```php
RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip()));
```
Laravel simpan counter dalam cache. Dengan Redis, `INCR` adalah **atomic** dan laju, jadi counter tetap tepat walaupun 100 request datang serentak.
(Fortify dalam starter kit ini sudah guna rate limiting untuk login.)

### 5. Atomic lock
Pastikan hanya **satu** process buat sesuatu pada satu masa:
```php
Cache::lock('generate-monthly-report', 120)->get(function () {
    // hanya satu worker/server boleh jalankan ini serentak
});
```
Contohnya, elak laporan dijana dua kali bila 2 worker ambil job yang sama, atau user klik button dua kali.

### 6. Lain-lain
| Ciri Laravel | Guna Redis untuk |
| --- | --- |
| **Horizon** | Dashboard queue (job, throughput, failed). Horizon **hanya** berfungsi dengan Redis. |
| **Broadcasting** | Hantar event real-time (Pub/Sub). |
| `ShouldBeUnique` job | Elak job yang sama masuk queue dua kali (guna lock). |
| `Cache::tags()` | Kumpul cache dan padam sekaligus (tidak disokong oleh driver `file`/`database`). |

---

## Bagaimana Redis digunakan dalam Job (langkah demi langkah)
Contoh guna job `SayHello` dan `SendUserMessage` dari [JobAndMail.md](JobAndMail.md). Semua output di bawah diambil dari Redis sebenar dalam projek ini.

### 4 key Redis untuk satu queue
Untuk queue `default`, Laravel guna 4 key (dengan prefix `laravel-database-`):

| Key | Jenis Redis | Isi |
| --- | --- | --- |
| `queues:default` | **List** | Job yang **sedia** dijalankan, ikut giliran (masuk dulu, keluar dulu) |
| `queues:default:delayed` | **Sorted set** | Job yang ada `delay()` atau menunggu retry. **Score** = waktu (Unix timestamp) job boleh dijalankan. |
| `queues:default:reserved` | **Sorted set** | Job yang **sedang** dijalankan oleh worker. **Score** = waktu tamat tempoh (sekarang + `retry_after`). |
| `queues:default:notify` | List | Isyarat "ada job baru" untuk worker (satu item setiap job) |

```bash
docker compose exec redis redis-cli keys '*queues*'
# laravel-database-queues:default
# laravel-database-queues:default:delayed
# laravel-database-queues:default:notify
```
(`reserved` hanya wujud semasa ada job yang sedang berjalan.)

### Kitaran hidup satu job
```
            dispatch()                         dispatch()->delay(30s)
                │                                       │
                │ RPUSH                                 │ ZADD score = sekarang+30
                ▼                                       ▼
        ┌──────────────────┐   bila masa tiba    ┌──────────────────────┐
        │ queues:default   │◄────────────────────│ queues:default:delayed│
        │ (list)           │  ZRANGEBYSCORE+RPUSH │ (sorted set)          │
        └────────┬─────────┘                     └──────────▲───────────┘
                 │ LPOP (worker ambil)                       │
                 │ attempts + 1                               │ gagal, masih ada cubaan:
                 ▼                                            │ ZADD score = sekarang+backoff
        ┌───────────────────────────┐                         │
        │ queues:default:reserved   │─────────────────────────┘
        │ score = sekarang + 90s    │
        └────────┬──────────────────┘
                 │
     ┌───────────┴───────────────┐
     ▼                           ▼
  berjaya                     gagal & cubaan habis
  ZREM dari reserved          ZREM dari reserved
  (job hilang dari Redis)     → simpan dalam table failed_jobs (MariaDB)
                              → panggil method failed() pada job
```

### Langkah 1: `dispatch()` simpan job dalam list
```php
SayHello::dispatch('Ali');
```
Laravel tukar job kepada **JSON (payload)**, kemudian jalankan script Lua dalam Redis:
```lua
redis.call('rpush', 'queues:default', payload)      -- masuk ke hujung list
redis.call('rpush', 'queues:default:notify', 1)     -- isyarat ada job baru
```
Kedua-dua command dijalankan dalam satu `EVAL`, jadi ia **atomic**: tiada process lain boleh mencelah di tengah-tengah.

Lihat payload (stop worker dahulu supaya job tidak terus diambil):
```bash
docker compose stop queue
# klik "Say hello → Queue 1 job" dalam page Users
docker compose exec redis redis-cli lrange laravel-database-queues:default 0 -1
```
```json
{
  "uuid": "b3967d67-c7a1-4387-8280-bb51f85540df",
  "displayName": "App\\Jobs\\SayHello",
  "job": "Illuminate\\Queue\\CallQueuedHandler@call",
  "maxTries": null,
  "backoff": null,
  "timeout": null,
  "data": {
    "commandName": "App\\Jobs\\SayHello",
    "command": "O:17:\"App\\Jobs\\SayHello\":1:{s:4:\"name\";s:3:\"Ali\";}"
  },
  "createdAt": 1791380148,
  "attempts": 0,
  "delay": null
}
```

| Field | Maksud |
| --- | --- |
| `uuid` | ID unik job. Digunakan oleh `queue:retry <uuid>` dan `queue:forget <uuid>`. |
| `displayName` | Nama class yang anda nampak dalam log worker (`App\Jobs\SayHello ... RUNNING`). |
| `data.command` | Object job yang di-**serialize** oleh PHP. Di sini property `name = "Ali"`. |
| `attempts` | Berapa kali sudah dicuba. `0` = belum pernah. |
| `maxTries`, `backoff`, `timeout` | Dari property job (`$tries`, `$backoff`, `$timeout`). `null` = guna setting worker (`--tries=3`). |

### Model disimpan sebagai ID sahaja
Payload `SendUserMessage` (yang ada `public User $user`):
```
"command": "O:24:\"App\\Jobs\\SendUserMessage\":3:{
    s:4:\"user\";O:45:\"Illuminate\\Contracts\\Database\\ModelIdentifier\":5:{
        s:5:\"class\";s:15:\"App\\Models\\User\";
        s:2:\"id\";i:1;                      ← hanya ID
        s:10:\"connection\";s:7:\"mariadb\"; ...}
    s:11:\"subjectLine\";s:2:\"Hi\";
    s:4:\"body\";s:4:\"Body\";}"
"maxTries": 3,
"backoff": "10",
```
- Redis **tidak** simpan seluruh data user (nama, email, password hash...). Ia hanya simpan `App\Models\User` + `id: 1`.
- Bila worker jalankan job, Laravel query semula `User::find(1)` dari MariaDB. Jadi worker sentiasa guna data **terkini**.
- Payload kekal kecil, dan data sensitif tidak ditulis ke Redis.
- `maxTries: 3` dan `backoff: "10"` datang dari `$tries` dan `$backoff` dalam class job.

> Jika user dipadam sebelum job dijalankan, job akan gagal (`ModelNotFoundException`). Tambah `public bool $deleteWhenMissingModels = true;` pada job untuk buang job itu secara senyap.

### Langkah 2: worker ambil job (`LPOP`) dan "reserve"
Worker (`php artisan queue:work`) berjalan dalam loop. Setiap kali ia ambil job, ia jalankan satu script Lua:
```lua
local job = redis.call('lpop', 'queues:default')         -- ambil dari DEPAN list
reserved = job dengan attempts + 1
redis.call('zadd', 'queues:default:reserved', sekarang + 90, reserved)
redis.call('lpop', 'queues:default:notify')
```
- `LPOP` ialah **atomic**. Jika 2 worker cuba ambil serentak, setiap job hanya diberi kepada **satu** worker.
- Job tidak terus dipadam. Ia dipindah ke **`reserved`** dengan masa tamat `sekarang + retry_after` (90 saat, dari `config/queue.php`).

Lihat semasa job sedang berjalan (`SayHello` ambil 3 saat):
```bash
docker compose exec redis redis-cli zrange laravel-database-queues:default:reserved 0 -1 withscores
# 1) "{...\"name\";s:8:\"Reserved\";}...,\"attempts\":1}"
# 2) "1791380255"          ← waktu sekarang (1791380165) + 90 saat
```

**Kenapa perlu `reserved`?** Bayangkan worker **crash** (container mati, server restart) di tengah-tengah job:
- Tanpa `reserved`, job sudah di-`LPOP` dan hilang selama-lamanya.
- Dengan `reserved`, selepas 90 saat job "tamat tempoh". Worker lain akan memindahkannya semula ke `queues:default` dan cuba lagi. **Tiada job hilang.**

> ⚠️ Kerana itu, `retry_after` (90 saat) mesti **lebih panjang** daripada job paling lama anda. Jika job ambil 120 saat, worker lain akan sangka ia crash dan jalankan job yang **sama** sekali lagi. Naikkan `REDIS_QUEUE_RETRY_AFTER` dalam `.env` jika ada job yang panjang.

### Langkah 3a: berjaya → buang dari Redis
`handle()` selesai tanpa error. Worker jalankan `ZREM` untuk buang job dari `reserved`, dan job **hilang dari Redis**.
Log worker tunjuk `DONE`.

### Langkah 3b: gagal → cuba lagi (`delayed`)
`handle()` throw exception (contoh: Mailpit mati, SMTP connection refused) dan `attempts < maxTries`:
```lua
redis.call('zrem', 'queues:default:reserved', job)
redis.call('zadd', 'queues:default:delayed', sekarang + backoff, job)
```
- Job dipindah ke `delayed` dengan score `sekarang + 10` (`$backoff = 10`).
- Log worker tunjuk `FAIL`. Selepas 10 saat, job kembali ke `queues:default` dan dicuba semula.

### Langkah 3c: cubaan habis → `failed_jobs` (MariaDB)
Bila `attempts` sudah sama dengan `maxTries` (3):
- Job dibuang dari Redis.
- Satu row ditulis dalam table **`failed_jobs` dalam MariaDB** (`config/queue.php` → `failed.driver = database-uuids`). Ia simpan payload penuh dan exception.
- Method `failed()` pada job dipanggil (contoh: `Log::error(...)`).

Kenapa MariaDB, bukan Redis? Job gagal ialah rekod **penting** yang perlu disiasat dan mungkin di-retry beberapa hari kemudian. Ia tidak sepatutnya hilang (ingat [peraturan mudah](#redis-vs-mariadb)).
```bash
docker compose exec app php artisan queue:failed        # baca dari MariaDB
docker compose exec app php artisan queue:retry all     # salin payload kembali ke Redis (RPUSH), dan padam dari failed_jobs
```

### Job dengan `delay()`
```php
SayHello::dispatch('Later')->delay(now()->addSeconds(30));
```
Job **tidak** masuk ke list. Ia masuk terus ke sorted set `delayed`:
```bash
docker compose exec redis redis-cli zrange laravel-database-queues:default:delayed 0 -1 withscores
# 1) "{\"uuid\":\"5ede46a8-...\",\"displayName\":\"App\\\\Jobs\\\\SayHello\",...}"
# 2) "1791380178"          ← waktu sekarang (1791380148) + 30 saat
```
Setiap kali worker cari job baru, ia mula-mula jalankan:
```lua
-- semua job dalam delayed yang score <= sekarang (sudah tiba masanya)
local jobs = redis.call('zrangebyscore', 'queues:default:delayed', '-inf', sekarang)
-- buang dari delayed, RPUSH ke queues:default
```
Sorted set membolehkan Redis cari "job yang sudah tiba masanya" dengan sangat cepat, tanpa perlu periksa setiap job satu-satu.
Worker buat perkara yang sama untuk `reserved`: job yang sudah tamat tempoh (worker crash) dipindah semula ke `queues:default`.

### Ringkasan: command Redis dalam setiap langkah
| Langkah | Command Redis | Key |
| --- | --- | --- |
| `dispatch()` | `RPUSH` | `queues:default`, `queues:default:notify` |
| `dispatch()->delay()` | `ZADD` (score = masa jalan) | `queues:default:delayed` |
| Worker semak job delay/tamat tempoh | `ZRANGEBYSCORE` → `RPUSH` | `delayed` / `reserved` → `queues:default` |
| Worker ambil job | `LPOP` + `ZADD` (score = sekarang + 90) | `queues:default` → `queues:default:reserved` |
| Job berjaya | `ZREM` | `queues:default:reserved` |
| Job gagal, cuba lagi | `ZREM` + `ZADD` (score = sekarang + backoff) | `reserved` → `delayed` |
| Job gagal, cubaan habis | `ZREM` + `INSERT` dalam MariaDB | `reserved` → table `failed_jobs` |

### Cuba sendiri
```bash
# Terminal 1: lihat semua command Redis secara live
docker compose exec redis redis-cli monitor
```
1. Klik **Say hello → Queue 1 job**. Dalam monitor anda nampak `EVAL` dengan `rpush`, kemudian `lpop` + `zadd ...reserved`, dan selepas 3 saat `zrem`.
2. `docker compose stop queue` → klik **Queue 4 jobs** → `redis-cli lrange laravel-database-queues:default 0 -1` → 4 payload JSON. `docker compose start queue` → list kosong.
3. Klik **Queue with 20s delay** → `redis-cli zrange laravel-database-queues:default:delayed 0 -1 withscores` → bandingkan score dengan `date +%s`.
4. `docker compose stop mailpit` → hantar email → semasa retry, lihat job dalam `delayed` dengan `"attempts":1`, kemudian `2`. Selepas 3 kali → `queue:failed` (MariaDB). `docker compose start mailpit` → `queue:retry all`.

---

## Bandingkan driver: file vs database vs redis
Laravel 11+ guna `database` sebagai default untuk cache, session dan queue (mudah, tiada service tambahan). Dalam projek ini kita tukar ke `redis`.

| | `file` | `database` | `redis` |
| --- | --- | --- | --- |
| Setup | Tiada | Table (`cache`, `sessions`, `jobs`) | Service Redis + extension PHP |
| Kelajuan | Sederhana (disk) | Sederhana (query SQL) | **Paling laju** (memory) |
| Banyak server / container | ❌ Tidak dikongsi | ✅ | ✅ |
| Beban pada MariaDB | Tiada | **Tambah** query setiap request | Tiada |
| Atomic lock & rate limit | Terhad | ✅ | ✅ (paling cekap) |
| Cache tags | ❌ | ❌ | ✅ |
| Horizon | ❌ | ❌ | ✅ |
| Sesuai untuk | Projek kecil, satu server | Projek kecil–sederhana | Projek sederhana–besar, banyak user, guna queue |

> Untuk **belajar** atau projek kecil, `database` sudah cukup. Bila trafik naik, atau anda guna banyak job, Redis memberi perbezaan yang besar.

---

## Bila Redis bukan pilihan yang betul
- **Data kekal yang penting** (users, transaksi, rekod kewangan). Simpan dalam MariaDB. Redis boleh hilang data terakhir jika crash sebelum sempat simpan ke disk.
- **Query kompleks** (`JOIN`, carian ikut banyak column, laporan). Redis tidak ada SQL.
- **Data sangat besar** yang tidak muat dalam RAM.
- **Projek yang sangat kecil** tanpa queue. Satu service tambahan mungkin tidak berbaloi. Driver `database` sudah memadai.

Perkara yang perlu dijaga dalam production:
- Set **password** (`REDIS_PASSWORD`), dan jangan buka port 6379 kepada internet.
- Pantau penggunaan **memory**. Jika RAM penuh, Redis akan tolak tulisan baru atau buang key lama (ikut setting `maxmemory-policy`).
- Jangan `FLUSHALL` sesuka hati. Ia padam cache, **session** (semua user logout), **dan job yang menunggu**.

---

## Redis dalam projek ini
```
                    ┌─────────────────────────────────────┐
  browser ──► app ──┤ Redis db0: session, queue (jobs)    │◄── queue (worker)
                    │ Redis db1: cache                    │
                    └─────────────────────────────────────┘
                    │
                    └──► MariaDB: users, roles, departments, projects (data kekal)
```

| Setting `.env` | Nilai | Maksud |
| --- | --- | --- |
| `CACHE_STORE` | `redis` | `Cache::...` simpan dalam Redis db1 |
| `SESSION_DRIVER` | `redis` | Login & flash message dalam Redis db0 |
| `QUEUE_CONNECTION` | `redis` | Job `dispatch()` masuk list `queues:default` dalam db0 |
| `REDIS_HOST` | `redis` | Nama service dalam `docker-compose.yml` |

Semak:
```bash
docker compose exec app php artisan about --only=drivers   # Cache/Queue/Session: redis
docker compose exec redis redis-cli info keyspace          # bilangan key dalam db0 dan db1
docker compose exec redis redis-cli monitor                # lihat semua command secara live (Ctrl+C untuk berhenti)
```

Setup penuh (service Docker, `.env`, dan cara test) ada dalam [JobAndMail.md Sesi 3](JobAndMail.md#sesi-3--job-pertama-faham-job-queue--worker).
