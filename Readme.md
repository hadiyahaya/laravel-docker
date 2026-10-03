# Tutorial Laravel + Docker

- [Sesi 1 — Cipta app Laravel](#sesi-1--cipta-app-laravel)
- [Sesi 2 — Jalankan app dalam Docker](#sesi-2--jalankan-app-dalam-docker)
- [Sesi 3 — Docker dengan MariaDB](#sesi-3--docker-dengan-mariadb)
- [Sesi 4 — Redis dan Mailpit](#sesi-4--redis-dan-mailpit)

---

# Sesi 1 — Cipta app Laravel

## Install Laravel installer
```bash
composer global require laravel/installer
```

## Cipta projek Laravel baru
```bash
laravel new laravel-docker
```

## Jawab soalan installer
| Soalan (prompt) | Jawapan |
| --- | --- |
| Which starter kit would you like to install? | Livewire |
| Which authentication provider do you prefer? | Laravel's built-in authentication |
| Would you like to use Laravel Volt? | No |
| Which testing framework do you prefer? | Pest |
| Which authentication features would you like to enable? | Registration, Password confirmation |
| Which database will your application use? | SQLite |
| Would you like to run the default database migrations? | Yes |
| Would you like to run `npm install` and `npm run build`? | Yes |

Selepas itu, installer akan cipta app, install dependency Composer dan npm,
cipta `database/database.sqlite`, jalankan migration dan build frontend assets.

## Masuk ke folder projek
```bash
cd laravel-docker
```

## Jalankan app secara local
```bash
composer run dev
```
Command ini jalankan PHP server, Vite, queue worker dan log viewer serentak.
Buka http://localhost:8000 — anda boleh register user dan login guna page auth yang sedia ada.

## Jalankan test
```bash
php artisan test
```

## Mulakan git
```bash
git init
git add .
git commit -m "first commit"
```

---

# Sesi 2 — Jalankan app dalam Docker

Matlamat: jalankan app yang sama dalam Docker menggunakan image [serversideup/php `fpm-nginx`](https://serversideup.net/open-source/docker-php/docs/image-variations/fpm-nginx).
Image ini ada **PHP-FPM dan nginx dalam satu container**, dan sudah dikonfigurasi untuk Laravel. Database masih SQLite.

```
browser ──► :8000 ──► app (nginx :8080 + php-fpm) ──► database/database.sqlite
```

Apa yang image ini sediakan secara default:
- nginx dengan web root di `/var/www/html/public`, listen pada port **8080** (HTTP) dan 8443 (HTTPS)
- berjalan sebagai user `www-data` (unprivileged), bukan root
- Composer, serta extension `pdo_mysql`, `redis`, `zip`, `pcntl` dan `opcache`
- script `install-php-extensions`, untuk tambah extension lain

> **Nota:** jika projek berada dalam external drive (contoh `/Volumes/...`), pastikan drive tersebut
> dibenarkan dalam Docker Desktop → Settings → Resources → File sharing.

Hentikan `composer run dev` dari Sesi 1 dahulu, kerana container akan guna port 8000.

## Struktur projek
Semua fail Docker diletakkan dalam folder `docker/`. Hanya `docker-compose.yml` dan `.dockerignore` kekal di root projek.
```
laravel-docker/
├── docker/
│   ├── Dockerfile
│   ├── php/
│   │   └── custom.ini      # PHP settings
│   └── nginx/
│       └── custom.conf     # nginx settings
├── .dockerignore
└── docker-compose.yml
```

## Cipta config PHP
`docker/php/custom.ini`
```ini
; Custom PHP settings for this project.
; Loaded after the serversideup defaults, so these values win.

memory_limit = 512M

; Keep these in sync with client_max_body_size in docker/nginx/custom.conf
upload_max_filesize = 200M
post_max_size = 200M

date.timezone = UTC
```
Image ini load setiap fail `.ini` dalam `/usr/local/etc/php/conf.d/` mengikut susunan abjad.
Kita copy fail kita sebagai `zzz-custom.ini` supaya ia di-load paling akhir dan override nilai default.

## Cipta config nginx
`docker/nginx/custom.conf`
```nginx
# Custom nginx settings for this project.
# This file is included inside the image's server { } block,
# so only server-level directives and location blocks belong here.

# Max request body size — keep in sync with post_max_size in docker/php/custom.ini
client_max_body_size 200M;
```
Image ini sudah ada config nginx yang lengkap untuk Laravel, jadi kita tidak gantikannya.
Ia include setiap fail dalam `/etc/nginx/server-opts.d/` di dalam block `server { }`. Kita tambah fail kita di situ.

## Cipta Dockerfile
`docker/Dockerfile`
```dockerfile
FROM serversideup/php:8.5-fpm-nginx

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

# Extra PHP extensions (pdo_mysql, redis, zip, pcntl and opcache are already included)
RUN install-php-extensions intl bcmath

# if need to install packages, use the following command
# Install Node.js v22
RUN apt-get update \
    && apt-get install -y curl ca-certificates gnupg \
    && curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
    && apt-get install -y nodejs

# Custom PHP settings (the "zzz-" prefix makes it load after the image's defaults)
COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zzz-custom.ini

# Custom nginx settings (included inside the image's server { } block)
COPY docker/nginx/custom.conf /etc/nginx/server-opts.d/custom.conf

# Match www-data UID/GID to the host user so mounted volumes are writable
RUN docker-php-serversideup-set-id www-data ${USER_ID}:${GROUP_ID} \
    && docker-php-serversideup-set-file-permissions --owner ${USER_ID}:${GROUP_ID}

# Drop back to the unprivileged user
USER www-data
```
Install extension dan copy fail config perlukan root, jadi kita tukar ke `root` untuk langkah tersebut, kemudian kembali ke `www-data`.
`docker-php-serversideup-set-id` menukar UID/GID `www-data` supaya sama dengan user di host anda, jadi fail yang ditulis ke folder projek (yang di-mount) ada owner yang betul.
Extension `pdo_mysql` dan `redis` yang kita perlukan dalam Sesi 3 dan 4 sudah ada dalam image.

Path `COPY` bermula dengan `docker/` kerana build context ialah root projek (lihat `docker-compose.yml` di bawah).

### Penerangan arahan (instruction) Dockerfile
Dockerfile ialah satu "resipi". Docker jalankannya dari atas ke bawah untuk build satu **image**, dan setiap instruction menambah satu **layer** di atas layer sebelumnya.

| Instruction | Fungsi | Dalam Dockerfile kita |
| --- | --- | --- |
| `FROM` | Base image yang digunakan sebagai permulaan. Mesti instruction pertama. | `FROM serversideup/php:8.5-fpm-nginx` — mula dari image yang sudah ada PHP 8.5, PHP-FPM dan nginx. |
| `USER` | User yang menjalankan instruction selepasnya, dan user yang menjalankan container. | `USER root` untuk install, kemudian `USER www-data` supaya app tidak berjalan sebagai root. |
| `ARG` | Pemboleh ubah (variable) masa build. Boleh ada nilai default dan boleh di-override semasa build. Ia **tidak** wujud semasa container berjalan. | `ARG USER_ID=1000` — digunakan kemudian sebagai `${USER_ID}`. Override dengan `docker compose build --build-arg USER_ID=501`. |
| `RUN` | Jalankan shell command semasa build image. Hasilnya disimpan dalam image. | `RUN install-php-extensions intl bcmath`, `RUN apt-get update ...` |
| `COPY` | Copy fail dari build context (projek anda) ke dalam image. | `COPY docker/php/custom.ini /usr/local/etc/php/conf.d/zzz-custom.ini` |

#### Instruction lain yang anda akan jumpa
Kita tidak perlukan instruction ini kerana base image serversideup sudah set semuanya, tetapi ia biasa digunakan dalam Dockerfile lain:

| Instruction | Fungsi | Contoh |
| --- | --- | --- |
| `WORKDIR` | Set working directory untuk instruction selepasnya (dan untuk `docker compose exec`). Akan dicipta jika belum wujud. | `WORKDIR /var/www/html` |
| `ENV` | Set environment variable yang wujud semasa build **dan** semasa container berjalan. | `ENV PHP_OPCACHE_ENABLE=1` |
| `EXPOSE` | Mendokumenkan port yang container listen. Ia tidak publish port — `ports:` dalam `docker-compose.yml` yang buat begitu. | `EXPOSE 8080` |
| `CMD` | Command default bila container start. Boleh diganti, contohnya dengan `command:` dalam `docker-compose.yml` (service `queue` kita buat begini). | `CMD ["php-fpm"]` |
| `ENTRYPOINT` | Command yang sentiasa dijalankan bila container start. `CMD` dihantar kepadanya sebagai argument. | `ENTRYPOINT ["docker-php-entrypoint"]` |
| `ADD` | Sama seperti `COPY`, tetapi boleh juga download URL dan extract fail `.tar`. Guna `COPY` kecuali anda perlukan fungsi itu. | `ADD app.tar.gz /app` |
| `HEALTHCHECK` | Command yang Docker jalankan secara berkala untuk semak sama ada container sihat (healthy). | `HEALTHCHECK CMD curl -f http://localhost/up` |

#### `ARG` vs `ENV`
| | `ARG` | `ENV` |
| --- | --- | --- |
| Ada semasa build | Ya | Ya |
| Ada dalam container yang sedang berjalan | Tidak | Ya |
| Set dari luar | `--build-arg` / `build.args:` dalam compose | `environment:` dalam compose |

#### Build vs run
- **Build time** (`docker compose build`): `FROM`, `ARG`, `RUN` dan `COPY` dijalankan sekali dan hasilnya disimpan dalam image.
  Jika anda ubah Dockerfile atau fail yang di-copy, perlu rebuild: `docker compose up -d --build`.
- **Run time** (`docker compose up`): container start daripada image dan jalankan `CMD` / `ENTRYPOINT`.
  Ubah `environment:` dalam `docker-compose.yml` hanya perlukan `docker compose up -d` (cipta semula container), tidak perlu rebuild.
  Ubah `.env` Laravel pula tidak perlu apa-apa — folder projek di-mount, jadi Laravel baca nilai baru pada request seterusnya
  (jalankan `php artisan config:clear` jika config di-cache, dan `docker compose restart queue` untuk worker).

## Cipta .dockerignore
`.dockerignore`
```
.git
node_modules
vendor
storage/logs
```
Source code di-mount sebagai volume, jadi tidak perlu hantar folder-folder ini ke Docker build.

## Cipta docker-compose.yml
`docker-compose.yml`
```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "8000:8080"
    volumes:
      - .:/var/www/html
    environment:
      HEALTHCHECK_PATH: /up
```
- `context: .` hantar root projek ke build. `dockerfile: docker/Dockerfile` beritahu Compose di mana Dockerfile berada.
- `8000:8080` petakan (map) port 8000 di komputer anda ke port 8080 nginx dalam container.
- `HEALTHCHECK_PATH: /up` buat health check terbina dalam image guna route `/up` Laravel.

## Build frontend assets
Build assets di komputer anda:
```bash
npm run build
```

## Start container
```bash
docker compose up -d --build
docker compose ps
```
Buka http://localhost:8000. `docker compose ps` akan tunjuk `app` sebagai **healthy** bila `/up` memberi respons.

## Semak custom config telah di-load
```bash
docker compose exec app php -i | grep -E "memory_limit|upload_max_filesize|post_max_size"
docker compose exec app nginx -T | grep client_max_body_size
```
PHP sepatutnya tunjuk `512M` / `200M` / `200M`. nginx tunjuk `100M` (default image dalam block `http { }`) dan
`200M` (milik kita, dalam block `server { }`). Nilai dalam `server` yang menang.

> Fail config di-copy ke dalam image, jadi selepas ubah fail tersebut, rebuild dengan `docker compose up -d --build`.

## Jalankan artisan dalam container
```bash
docker compose exec app php artisan migrate:status
docker compose exec app php artisan test
```

## Command berguna
```bash
docker compose logs -f        # follow logs
docker compose down           # stop and remove containers
```

---

# Sesi 3 — Docker dengan MariaDB

Matlamat: gantikan SQLite dengan container **MariaDB**.

```
browser ──► :8000 ──► app (nginx + php-fpm) ──► mariadb :3306
```

## Tambah service mariadb
Dalam `docker-compose.yml`, buat `app` tunggu database, kemudian tambah service `mariadb` dan satu named volume:
```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "8000:8080"
    volumes:
      - .:/var/www/html
    environment:
      HEALTHCHECK_PATH: /up
    depends_on:
      mariadb:
        condition: service_healthy

  mariadb:
    image: mariadb:11.4
    ports:
      - "${FORWARD_DB_PORT:-3306}:3306"
    environment:
      MARIADB_ROOT_PASSWORD: ${DB_PASSWORD}
      MARIADB_DATABASE: ${DB_DATABASE}
      MARIADB_USER: ${DB_USERNAME}
      MARIADB_PASSWORD: ${DB_PASSWORD}
    volumes:
      - mariadb-data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 5s
      timeout: 5s
      retries: 10

volumes:
  mariadb-data:
```
- `${DB_DATABASE}` dan variable lain diambil dari `.env`. Docker Compose baca fail ini secara automatik.
- `mariadb-data` simpan data walaupun container dibuang.
- Healthcheck buat `app` tunggu sehingga MariaDB sedia.
- `FORWARD_DB_PORT` membolehkan anda tukar port host jika 3306 sudah digunakan.

## Kemas kini .env
Gantikan baris SQLite ini:
```dotenv
DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=laravel
# DB_USERNAME=root
# DB_PASSWORD=
```
dengan:
```dotenv
DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=laravel
DB_PASSWORD=secret
```
`DB_HOST=mariadb` ialah nama service, bukan `127.0.0.1`. Di dalam container, `127.0.0.1` merujuk kepada container itu sendiri.

## Start dan migrate
```bash
docker compose up -d
docker compose exec app php artisan config:clear
docker compose exec app php artisan migrate
docker compose exec app php artisan db:show
```
`db:show` sepatutnya tunjuk **MariaDB** dengan host `mariadb`.

## Sambung guna GUI (TablePlus, DBeaver, ...)
| Field | Nilai |
| --- | --- |
| Host | 127.0.0.1 |
| Port | 3306 |
| User | laravel |
| Password | secret |
| Database | laravel |

---

# Sesi 4 — Redis dan Mailpit

Matlamat: guna **Redis** untuk cache, session dan queue; tangkap semua email keluar dalam **Mailpit**; jalankan container **queue worker**.

```
browser ──► :8000 ──► app ──► mariadb
                       ├───► redis      (cache, session, queue)
                       └───► mailpit    (SMTP :1025, web UI :8025)
queue worker ──► redis + mailpit
```

## Tambah service
Dalam `docker-compose.yml`, buat `app` tunggu Redis, tambah `queue` worker, dan tambah service `redis` dan `mailpit`:
```yaml
services:
  app:
    build:
      context: .
      dockerfile: docker/Dockerfile
    ports:
      - "8000:8080"
    volumes:
      - .:/var/www/html
    environment:
      HEALTHCHECK_PATH: /up
    depends_on:
      mariadb:
        condition: service_healthy
      redis:
        condition: service_healthy

  queue:
    build:
      context: .
      dockerfile: docker/Dockerfile
    command: ["php", "/var/www/html/artisan", "queue:work", "--tries=3"]
    stop_signal: SIGTERM
    volumes:
      - .:/var/www/html
    healthcheck:
      test: ["CMD", "healthcheck-queue"]
      start_period: 10s
    depends_on:
      - app

  # mariadb: ... (unchanged)

  redis:
    image: redis:7-alpine
    ports:
      - "${FORWARD_REDIS_PORT:-6379}:6379"
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 5s
      retries: 10

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "${FORWARD_MAILPIT_PORT:-1025}:1025"
      - "${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025"

volumes:
  mariadb-data:
  redis-data:
```
Service `queue` guna image yang sama dengan `app`, tetapi `command`-nya jalankan queue worker, bukan nginx + PHP-FPM.
- `stop_signal: SIGTERM` membolehkan worker habiskan job semasa sebelum container berhenti.
- `healthcheck-queue` ialah script health check yang disediakan dalam image serversideup.

## Kemas kini .env
```dotenv
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
```
Jika port 6379 (atau 1025 / 8025) sudah digunakan di komputer anda, tukar port host sahaja, contoh:
```dotenv
FORWARD_REDIS_PORT=6380
```
Container masih berhubung sesama sendiri menggunakan port biasa.

## Start semua service
```bash
docker compose up -d
docker compose exec app php artisan config:clear
docker compose restart queue
docker compose ps
```
Anda sepatutnya nampak `app`, `queue`, `mariadb`, `redis` dan `mailpit` berjalan dan **healthy**.

## Test Redis
```bash
docker compose exec app php artisan tinker --execute="Cache::put('ping', 'pong', 60); echo Cache::get('ping');"
```
Ia sepatutnya print `pong`.

## Test queue + Mailpit
Masukkan satu email ke dalam queue:
```bash
docker compose exec app php artisan tinker --execute="Mail::to('test@example.com')->queue((new Illuminate\Mail\Mailable)->subject('Queued test')->html('<p>Hello from the queue</p>'));"
```
Lihat worker memprosesnya:
```bash
docker compose logs -f queue
```
Buka http://localhost:8025 — email **Queued test** ada dalam inbox Mailpit.

> Selepas ubah code yang digunakan oleh worker, jalankan `docker compose restart queue`. Worker simpan code lama dalam memory.

## URL service
| Service | URL / Port |
| --- | --- |
| App | http://localhost:8000 |
| Mailpit UI | http://localhost:8025 |
| MariaDB | 127.0.0.1:3306 |
| Redis | 127.0.0.1:6379 (atau `FORWARD_REDIS_PORT`) |
