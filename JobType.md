# Synchronous vs Asynchronous

- [Maksud ringkas](#maksud-ringkas)
- [Analogi](#analogi)
- [Dalam app Laravel ini](#dalam-app-laravel-ini)
- [Kenapa penting: contoh email](#kenapa-penting-contoh-email)
- [Bila guna sync, bila guna async](#bila-guna-sync-bila-guna-async)
- [Kelemahan async](#kelemahan-async)
- [`QUEUE_CONNECTION=sync`](#queue_connectionsync)

---

## Maksud ringkas
| | Maksud |
| --- | --- |
| **Synchronous (sync)** | "Tunggu sampai siap, baru teruskan." Kerja dibuat **sekarang**, dalam process yang sama, dan pemanggil **menunggu**. |
| **Asynchronous (async)** | "Serahkan kerja, teruskan dulu." Kerja dibuat **kemudian**, di **tempat lain**, dan pemanggil **tidak** menunggu. |

---

## Analogi
- **Synchronous:** kaunter makanan segera. Anda order, kemudian **berdiri** di kaunter sampai makanan siap, baru pergi. Orang di belakang anda tidak dilayan sehingga anda selesai.
- **Asynchronous:** restoran. Anda order, dapat **nombor**, dan duduk. Dapur masak di belakang, sementara kaunter terus layan pelanggan seterusnya. Makanan sampai bila siap.

| Restoran | Laravel |
| --- | --- |
| Pelanggan | Browser / user |
| Kaunter | Container `app` (request) |
| Kertas order | Job (payload JSON) |
| Papan order dapur | Redis (`queues:default`) |
| Tukang masak | Container `queue` (worker) |

---

## Dalam app Laravel ini
Dropdown **Say hello** dalam page Users ([JobAndMail.md Sesi 3](JobAndMail.md#sesi-3--job-pertama-faham-job-queue--worker)) tunjuk kedua-duanya:

| | **Run now (sync)** | **Queue 1 job (async)** |
| --- | --- | --- |
| Code | `SayHello::dispatchSync($name)` | `SayHello::dispatch($name)` |
| Siapa jalankan `handle()` | Request itu sendiri (container `app`) | Worker (container `queue`) |
| Browser | Loading **3 saat** | Kembali **serta-merta** |
| Jika `handle()` gagal | User nampak **error page** | User tidak nampak apa-apa. Job dicuba semula kemudian. |

### Synchronous
```
Browser ──request──► app: jalankan handle() ... 3 saat ... siap ──response──► Browser
                     (user menunggu sepanjang masa)
```

### Asynchronous
```
Browser ──request──► app: RPUSH job ke Redis ──response (laju)──► Browser
                                  │
                                  ▼
                     worker queue: LPOP → handle() ... 3 saat ... siap
                     (berlaku di belakang tabir, user tidak menunggu)
```

Cuba sendiri:
1. Buka `docker compose logs -f queue` dalam terminal.
2. Klik **Say hello → Run now (sync)**. Browser loading 3 saat, dan log worker **kosong**.
3. Klik **Say hello → Queue 1 job**. Browser terus kembali, dan log worker tunjuk `RUNNING` → `3s DONE`.

---

## Kenapa penting: contoh email
| | Sesi 2 (sync) | Sesi 4–5 (async) |
| --- | --- | --- |
| Code | `Mail::to($user)->send(...)` | `SendUserMessage::dispatch(...)` |
| Masa request | 1–5 saat **setiap email** (SMTP sebenar) | Beberapa **milisaat** (hanya simpan job dalam Redis) |
| Hantar kepada 100 user | 100 × 1–5 saat → request **timeout** | 100 job dalam Redis, request tetap laju. Worker hantar satu demi satu. |
| SMTP down | User nampak **error 500**, email hilang | Job dicuba semula 3 kali, kemudian masuk `failed_jobs`, dan boleh `queue:retry` |

---

## Bila guna sync, bila guna async
**Guna sync** bila user perlukan hasilnya **sekarang**, untuk ditunjuk pada page seterusnya:
- Simpan form (create/update user)
- Semak password semasa login
- Papar senarai users

**Guna async (queue)** bila kerja itu **lambat**, **boleh gagal**, atau user **tidak perlu tunggu**:
- Hantar email / notifikasi
- Jana PDF atau laporan
- Panggil API luar (payment, SMS)
- Resize gambar / video
- Bulk action (contoh **Email all**)

> **Soalan mudah:** "Perlukah user tunggu kerja ini siap sebelum nampak page seterusnya?"
> **Ya** → sync. **Tidak** → async.

---

## Kelemahan async
| Kelemahan | Maksud |
| --- | --- |
| Perlu bahagian tambahan | Redis dan worker (`queue`) mesti berjalan. Jika worker mati, job hanya bertimbun dalam Redis. |
| Hasil tidak serta-merta | User nampak "Email **queued**", bukan "Email **sent**". |
| Worker simpan code lama | Mesti `docker compose restart queue` selepas ubah code job. |
| Lebih susah debug | Error muncul dalam `docker compose logs queue` dan `queue:failed`, bukan pada page. |
| Data mungkin berubah | Job dijalankan kemudian, jadi data mungkin sudah berubah (contoh user sudah dipadam). |

---

## `QUEUE_CONNECTION=sync`
Dalam `.env`, `QUEUE_CONNECTION=sync` tukar **semua** `dispatch()` menjadi synchronous (seperti `dispatchSync()`). Tiada Redis, tiada worker.

| Nilai | Kesan |
| --- | --- |
| `QUEUE_CONNECTION=redis` | `dispatch()` = async (projek ini) |
| `QUEUE_CONNECTION=sync` | `dispatch()` = sync. Berguna untuk **debug**, kerana error terus nampak pada page. |

Test dalam projek ini juga guna `sync` (lihat `phpunit.xml`):
```xml
<env name="QUEUE_CONNECTION" value="sync"/>
```
Jadi semasa test, job dijalankan terus tanpa perlukan worker.

> Selepas tukar `.env`, jalankan `docker compose exec app php artisan config:clear` dan `docker compose restart queue`.

---

Lihat juga:
- [JobAndMail.md](JobAndMail.md): tutorial penuh job, queue dan email
- [Redis.md](Redis.md#bagaimana-redis-digunakan-dalam-job-langkah-demi-langkah): apa yang berlaku dalam Redis untuk setiap job
