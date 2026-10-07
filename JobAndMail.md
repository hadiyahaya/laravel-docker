# Tutorial Email & Job Queue (Mailpit + Mailable + Job)

Tutorial ini sambung dari [PermissionPolicy.md](PermissionPolicy.md) — kita guna semula page **Users** (`/users`) untuk hantar email kepada user.
Cara sama seperti sebelum ini: **Route → Controller → Blade view** dengan form HTML (tanpa Livewire), dan akses dikawal oleh **Policy**.
Semua command `php artisan` dijalankan **dalam container** guna `docker compose exec app ...`.

- [Sesi 1 — Mailpit dalam Docker & .env](#sesi-1--mailpit-dalam-docker--env)
- [Sesi 2 — Mailable: hantar email dari page Users](#sesi-2--mailable-hantar-email-dari-page-users)
- [Sesi 3 — Job pertama: faham Job, Queue & Worker](#sesi-3--job-pertama-faham-job-queue--worker)
- [Sesi 4 — Job & Queue: hantar email di background](#sesi-4--job--queue-hantar-email-di-background)
- [Sesi 5 — Hantar email kepada ramai user (bulk)](#sesi-5--hantar-email-kepada-ramai-user-bulk)

### Gambaran keseluruhan
```
Page Users ──► UserEmailController ──dispatch──► SendUserMessage (Job)
  (button Email)        │                               │
                        │ terus redirect                ▼
                        ▼                         redis (queue)
                "Email queued."                         │
                                                        ▼
                                          container queue (queue:work)
                                                        │  Mail::to($user)->send(new UserMessage)
                                                        ▼
                                              mailpit (SMTP :1025)
                                                        │
                                                        ▼
                                         http://localhost:8025 (inbox)
```

| Bahagian | Fail | Fungsi |
| --- | --- | --- |
| Mailpit | `docker-compose.yml`, `.env` | Server SMTP palsu, tangkap semua email untuk development |
| Mailable | `app/Mail/UserMessage.php` | **Apa** isi email (subject, view, data) |
| Job | `app/Jobs/SendUserMessage.php` | **Kerja** yang dijalankan di background (hantar email) |
| Queue worker | service `queue` | Ambil job dari Redis dan jalankan satu-satu |
| Policy | `UserPolicy::email()`, `emailAny()` | **Siapa** boleh hantar email |

---

# Sesi 1 — Mailpit dalam Docker & .env

Matlamat: semua email yang app hantar ditangkap oleh **Mailpit**, supaya kita boleh lihat email dalam browser tanpa email betul dihantar keluar.

> Jika anda sudah ikut [Readme.md Sesi 4](Readme.md#sesi-4--redis-dan-mailpit), service ini sudah ada. Baca sesi ini untuk faham setiap baris.

## Apa itu Mailpit?
Mailpit ialah **server SMTP palsu**. Laravel hantar email kepadanya seperti hantar ke Gmail/SES, tetapi Mailpit **tidak** hantar ke mana-mana. Ia simpan email dan tunjuk dalam web UI.

```
Laravel ──SMTP :1025──► mailpit ──► web UI :8025 (browser anda)
```

## Tambah service dalam docker-compose.yml
`docker-compose.yml`
```yaml
services:
  # ... app, queue, mariadb, redis ...

  mailpit:
    image: axllent/mailpit:latest
    ports:
      - "${FORWARD_MAILPIT_PORT:-1025}:1025"
      - "${FORWARD_MAILPIT_DASHBOARD_PORT:-8025}:8025"
```

| Baris | Maksud |
| --- | --- |
| `mailpit:` | Nama service. Ia juga jadi **hostname** dalam network Docker, jadi container lain sambung ke `mailpit`. |
| `image: axllent/mailpit:latest` | Image rasmi Mailpit dari Docker Hub. Tiada Dockerfile diperlukan. |
| `1025` | Port **SMTP**. Laravel hantar email ke sini. |
| `8025` | Port **web UI**. Buka dalam browser untuk lihat inbox. |
| `${FORWARD_MAILPIT_PORT:-1025}` | Port pada **host** (Mac anda). Guna nilai dari `.env`, atau `1025` jika tiada. Tukar jika port sudah digunakan. |

> Service `app` dan `queue` tidak perlu `depends_on: mailpit`. Email hanya dihantar bila ada request/job, bukan semasa container start.

## Kemas kini .env
`.env`
```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=null
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"

# Docker host ports - change these if a port is already in use on your machine
# FORWARD_MAILPIT_PORT=1025
# FORWARD_MAILPIT_DASHBOARD_PORT=8025
```

| Key | Kenapa |
| --- | --- |
| `MAIL_MAILER=smtp` | Hantar guna protokol SMTP (bukan `log`). |
| `MAIL_HOST=mailpit` | Nama **service** Docker. **Bukan** `127.0.0.1`, kerana dalam container `127.0.0.1` ialah container itu sendiri. |
| `MAIL_PORT=1025` | Port SMTP **dalam** network Docker (sentiasa 1025, walaupun `FORWARD_MAILPIT_PORT` ditukar). |
| `MAIL_USERNAME/PASSWORD=null` | Mailpit tidak perlukan login. |
| `MAIL_FROM_*` | Alamat "From" default untuk semua email. |

Salin juga key yang sama ke `.env.example` supaya ahli team lain dapat setting yang betul.

## Start dan test
```bash
docker compose up -d
docker compose exec app php artisan config:clear
```

Hantar satu email terus (tanpa queue):
```bash
docker compose exec app php artisan tinker --execute="Mail::raw('Hello from Laravel', fn (\$m) => \$m->to('test@example.com')->subject('Mailpit test'));"
```

Buka http://localhost:8025. Email **Mailpit test** ada dalam inbox.

## Cuba sendiri
1. `docker compose ps`: service `mailpit` berjalan.
2. Tukar `MAIL_HOST=127.0.0.1`, jalankan `config:clear`, dan cuba tinker sekali lagi. Anda akan dapat error **Connection refused**. Tukar balik ke `mailpit`.
3. Klik email dalam Mailpit dan lihat tab **HTML**, **Text**, **Headers** dan **HTML Check**.

---

# Sesi 2 — Mailable: hantar email dari page Users

Matlamat: button **Email** pada setiap row dalam page Users. Klik untuk buka form (subject + mesej), dan hantar email kepada user itu. Hanya role yang ada permission `email users` boleh guna.

## Tambah permission `email users`
`database/seeders/RolePermissionSeeder.php`
```php
$permissions = [
    'view users',
    'create users',
    'edit users',
    'delete users',
    'email users',
];
```
`super-admin` dan `admin` dapat semua `$permissions`, jadi mereka dapat `email users` secara automatik. `staff` kekal `view users` sahaja.

```bash
docker compose exec app php artisan db:seed --class=RolePermissionSeeder
docker compose exec app php artisan permission:cache-reset
```

## Tambah method dalam Policy
`app/Policies/UserPolicy.php` (tambah di bawah `delete()`)
```php
/**
 * Can the user send an email to this user?
 * Nobody needs to email themselves from here.
 */
public function email(User $user, User $model): bool
{
    return $user->can('email users')
        && $user->isNot($model);
}
```

## Cipta Mailable
```bash
docker compose exec app php artisan make:mail UserMessage --markdown=mail.user-message
```
Command ini cipta 2 fail: class `app/Mail/UserMessage.php` dan view `resources/views/mail/user-message.blade.php`.

`app/Mail/UserMessage.php`
```php
<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public User $user,
        public string $subjectLine,
        public string $body,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.user-message',
        );
    }
}
```

- Property `public` dalam constructor boleh terus digunakan dalam view (`$user`, `$body`).
- Kita guna nama `$subjectLine`, **bukan** `$subject`, kerana class `Mailable` sudah ada property `$subject` sendiri.
- Jangan namakan variable `$message`. Dalam view email, `$message` sudah dikhaskan oleh Laravel.

`resources/views/mail/user-message.blade.php`
```blade
<x-mail::message>
# Hi {{ $user->name }},

{{ $body }}

<x-mail::button :url="route('dashboard')">
Open {{ config('app.name') }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
```
> View **markdown** guna component `<x-mail::...>`. Laravel tukar ia ke HTML dengan styling email yang cantik dan juga versi text biasa.
> Jangan indent baris dalam fail ini. Markdown anggap baris yang di-indent sebagai blok code.

## Cipta controller
```bash
docker compose exec app php artisan make:controller UserEmailController
```

`app/Http/Controllers/UserEmailController.php` (versi **synchronous**, akan ditukar dalam Sesi 4)
```php
<?php

namespace App\Http\Controllers;

use App\Mail\UserMessage;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class UserEmailController extends Controller
{
    /**
     * Show the "Send email" form for one user.
     */
    public function create(User $user): View
    {
        return view('users.email', [
            'user' => $user,
        ]);
    }

    /**
     * Send an email to one user.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        Mail::to($user)->send(new UserMessage($user, $validated['subject'], $validated['body']));

        return to_route('users.index')->with('status', __('Email sent to :name.', ['name' => $user->name]));
    }
}
```
`Mail::to($user)` terima model User terus. Laravel ambil `email` dan `name` dari model.

## Route
`routes/web.php` (tambah `use` di atas, dan route dalam group `auth`)
```php
use App\Http\Controllers\UserEmailController;

// ...

Route::get('users/{user}/email', [UserEmailController::class, 'create'])
    ->can('email', 'user')
    ->name('users.email.create');

Route::post('users/{user}/email', [UserEmailController::class, 'store'])
    ->can('email', 'user')
    ->name('users.email.store');
```

## View form
`resources/views/users/email.blade.php`
```blade
<x-layouts::app :title="__('Send email')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Send email') }}</flux:heading>
        <flux:subheading class="mb-6">{{ __('To') }}: {{ $user->name }} ({{ $user->email }})</flux:subheading>

        <form method="POST" action="{{ route('users.email.store', $user) }}" class="space-y-6">
            @csrf

            <flux:input name="subject" :label="__('Subject')" :value="old('subject')" required />

            <flux:textarea name="body" :label="__('Message')" rows="6" required>{{ old('body') }}</flux:textarea>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                <flux:button :href="route('users.index')" variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
```

## Button dalam table Users
`resources/views/users/index.blade.php`: dalam `<div class="flex justify-end gap-1">`, tambah **sebelum** button Edit role:
```blade
@can('email', $user)
    <flux:button size="sm" variant="ghost" icon="envelope" :href="route('users.email.create', $user)">
        {{ __('Email') }}
    </flux:button>
@endcan
```

## Masalah dengan cara synchronous
`Mail::to()->send()` hantar email **semasa request**. Browser perlu tunggu sehingga server SMTP selesai.
- Mailpit laju (local), jadi kita tidak perasan.
- SMTP sebenar (Gmail, SES, Mailgun) boleh ambil masa 1–5 saat setiap email. Jika server SMTP down, user nampak **error 500**.
- Hantar kepada 100 user = 100 × masa itu dalam satu request, dan request akan **timeout**.

Untuk rasa sendiri, tambah `sleep(3);` di awal `store()`, hantar email, dan perhatikan browser "loading" 3 saat. Buang semula selepas cuba.

Penyelesaiannya ialah **Job + Queue**. Dalam Sesi 3 kita belajar job yang paling ringkas dahulu, kemudian guna untuk email dalam Sesi 4.

## Cuba sendiri
1. Login `admin@example.com` / `password` → **Users**. Ada button **Email** pada semua row kecuali row sendiri.
2. Klik **Email** → isi subject & mesej → **Send** → mesej "Email sent to ...". Buka http://localhost:8025, email ada dengan nama user dalam "Hi ...".
3. Hantar form kosong → error validation pada Subject dan Message.
4. Login `staff@example.com` → tiada button Email. Buka `http://localhost:8000/users/5/email` → **403**.

---

# Sesi 3 — Job pertama: faham Job, Queue & Worker

Matlamat: tulis job yang **paling ringkas** (tiada email) supaya kita faham bagaimana job masuk ke queue dan dijalankan oleh worker. Kita jalankan job dari button **Say hello** di sebelah **Email all** dalam page Users.

## Konsep
| Istilah | Maksud |
| --- | --- |
| **Job** | Class yang ada satu kerja (method `handle()`). Implement `ShouldQueue` supaya ia dimasukkan ke queue, bukan dijalankan terus. |
| **Queue** | Senarai job yang menunggu. Kita simpan dalam **Redis** (`QUEUE_CONNECTION=redis`). |
| **Worker** | Process `php artisan queue:work` yang ambil job dari queue dan jalankan `handle()`. Dalam projek ini ia ialah service `queue` dalam `docker-compose.yml`. |
| **Dispatch** | Masukkan job ke queue: `SayHello::dispatch('Ali')`. |

> Jika anda sudah ikut [Readme.md Sesi 4](Readme.md#sesi-4--redis-dan-mailpit), Redis dan worker sudah ada. Baca bahagian setup di bawah untuk faham setiap baris.

## Setup Redis dalam Docker
**Redis** ialah database dalam memory (key → value) yang sangat laju. Dalam projek ini ia simpan 3 perkara:

| Guna | Setting `.env` | Redis database |
| --- | --- | --- |
| **Queue** (job menunggu) | `QUEUE_CONNECTION=redis` | `db0` (connection `default`) |
| **Session** (login user) | `SESSION_DRIVER=redis` | `db0` |
| **Cache** (`Cache::put()`) | `CACHE_STORE=redis` | `db1` (connection `cache`) |

### Tambah service `redis`
`docker-compose.yml`
```yaml
services:
  app:
    # ...
    depends_on:
      mariadb:
        condition: service_healthy
      redis:
        condition: service_healthy

  # ... queue, mariadb ...

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

volumes:
  mariadb-data:
  redis-data:
```

| Baris | Maksud |
| --- | --- |
| `redis:` | Nama service, dan juga **hostname** dalam network Docker. Laravel sambung ke `redis:6379`. |
| `image: redis:7-alpine` | Image rasmi Redis 7. Versi `alpine` lebih kecil. |
| `${FORWARD_REDIS_PORT:-6379}:6379` | Port pada host (Mac anda), untuk GUI seperti TablePlus/RedisInsight. Tukar dalam `.env` jika 6379 sudah digunakan. |
| `redis-data:/data` | **Named volume**. Data Redis (termasuk job yang menunggu) kekal walaupun container dipadam/dicipta semula. |
| `healthcheck` | `redis-cli ping` mesti jawab `PONG`. Docker tanda container **healthy**. |
| `app` → `depends_on: redis: condition: service_healthy` | Container `app` hanya start selepas Redis sedia. Jika tidak, request pertama (session) boleh gagal. |
| `volumes: redis-data:` (paling bawah) | Daftar named volume. Tanpa ini, `docker compose up` beri error. |

> **PHP extension:** image `serversideup/php` sudah ada extension `redis` (phpredis), jadi tiada perubahan pada Dockerfile.
> Semak: `docker compose exec app php -m | grep redis`

### Kemas kini .env
`.env`
```dotenv
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
CACHE_STORE=redis

REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Docker host ports - change these if a port is already in use on your machine
# FORWARD_REDIS_PORT=6379
```

| Key | Kenapa |
| --- | --- |
| `REDIS_CLIENT=phpredis` | Guna extension PHP `redis` (laju, sudah ada dalam image). |
| `REDIS_HOST=redis` | Nama **service** Docker. **Bukan** `127.0.0.1`, kerana dalam container `127.0.0.1` ialah container itu sendiri. |
| `REDIS_PORT=6379` | Port **dalam** network Docker (sentiasa 6379, walaupun `FORWARD_REDIS_PORT` ditukar). |
| `REDIS_PASSWORD=null` | Tiada password untuk development. Dalam production, **wajib** set password. |

Salin juga key yang sama ke `.env.example`.

### Start dan test Redis
```bash
docker compose up -d
docker compose exec app php artisan config:clear
docker compose ps                                  # redis: Up (healthy)
```

```bash
docker compose exec redis redis-cli ping           # PONG
docker compose exec app php artisan about --only=drivers
#  Cache ......... redis
#  Queue ......... redis
#  Session ....... redis
```

Cuba dari Laravel:
```bash
docker compose exec app php artisan tinker --execute="dump(Redis::connection()->ping()); Cache::put('tutorial', 'hello redis', 60); dump(Cache::get('tutorial'));"
# true
# "hello redis"
```

Lihat key dalam Redis:
```bash
docker compose exec redis redis-cli info keyspace          # bilangan key dalam db0 dan db1
docker compose exec redis redis-cli -n 1 keys '*tutorial*' # laravel-database-laravel-cache-tutorial
```
> `-n 1` pilih database `1` (cache). Prefix `laravel-database-` datang dari `APP_NAME` (lihat `config/database.php`, `redis.options.prefix`).

## Setup queue worker dalam Docker
Job dalam Redis tidak akan berjalan sendiri. Kita perlukan process `php artisan queue:work` yang sentiasa hidup. Kita jalankan ia sebagai **container berasingan**.

`docker-compose.yml`
```yaml
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
```

| Baris | Maksud |
| --- | --- |
| `build:` | Image yang **sama** dengan `app` (PHP + extension + code yang sama). |
| `command: [... "queue:work", "--tries=3"]` | Ganti command default (nginx + PHP-FPM) dengan worker. `--tries=3`: cuba job 3 kali sebelum gagal. |
| `stop_signal: SIGTERM` | Bila container di-stop, worker **habiskan job semasa** dahulu, baru berhenti. |
| `volumes: .:/var/www/html` | Code yang sama dengan `app`. |
| `healthcheck-queue` | Script dari image serversideup untuk semak worker masih hidup. |
| `depends_on: app` | Start selepas `app` (yang sudah tunggu Redis dan MariaDB). |

```bash
docker compose up -d
docker compose ps                    # queue: Up (healthy)
```

Aliran job:
```
button Say hello ──► SayHelloController ──dispatch──► redis (senarai job menunggu) ──► container queue (queue:work) ──► handle()
                              │
                              └── terus redirect (tidak tunggu handle() selesai)
```

## Cipta job
```bash
docker compose exec app php artisan make:job SayHello
```

`app/Jobs/SayHello.php`
```php
<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SayHello implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $name,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Pretend this is slow work (calling an API, making a PDF, ...)
        sleep(3);

        Log::info("Hello, {$this->name}!");
    }
}
```

| Bahagian | Fungsi |
| --- | --- |
| `implements ShouldQueue` | Bila di-dispatch, job **masuk ke queue** (Redis). Tanpa ini, job dijalankan terus. |
| `use Queueable` | Beri method seperti `delay()` dan `onQueue()`. |
| `__construct(public string $name)` | Data yang job perlukan. Data ini disimpan dalam Redis bersama job. |
| `handle()` | Kerja sebenar. Dijalankan oleh **worker**, bukan oleh request. |
| `sleep(3)` | Pura-pura kerja lambat, supaya kita nampak beza dengan dan tanpa queue. |

Worker perlu di-restart supaya kenal class baru:
```bash
docker compose restart queue
```

## Controller
Satu button, 4 cara jalankan job yang sama. Kita guna **invokable controller** (satu method `__invoke`), kerana ia hanya ada satu action.
```bash
docker compose exec app php artisan make:controller SayHelloController --invokable
```

`app/Http/Controllers/SayHelloController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\SayHello;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SayHelloController extends Controller
{
    /**
     * Run the SayHello job in different ways, to see how the queue works.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['queue', 'sync', 'many', 'delay'])],
            'search' => ['nullable', 'string'],
        ]);

        $name = $request->user()->name;

        switch ($validated['mode']) {
            case 'queue':
                // Put the job in Redis. The queue worker runs it, the page returns at once.
                SayHello::dispatch($name);
                $status = __('1 job queued. Watch the queue log.');
                break;

            case 'sync':
                // Run the job now, inside this request. The page waits 3 seconds.
                SayHello::dispatchSync($name);
                $status = __('Job finished in this request (you waited 3 seconds).');
                break;

            case 'many':
                // One job for each of the first 4 users in the table. The worker runs them one by one.
                $names = User::query()->search($validated['search'] ?? null)->latest()->limit(4)->pluck('name');
                $names->each(fn (string $name) => SayHello::dispatch($name));
                $status = __(':count jobs queued. The worker runs them one by one.', ['count' => $names->count()]);
                break;

            default: // delay
                // Put the job in Redis now, but the worker only runs it after 20 seconds.
                SayHello::dispatch($name)->delay(now()->addSeconds(20));
                $status = __('1 job queued. It will run in 20 seconds.');
        }

        return to_route('users.index', ['search' => $validated['search'] ?? null])
            ->with('status', $status);
    }
}
```
> `User::query()->search(...)` ialah scope dari [Eloquent.md Sesi 2](Eloquent.md#sesi-2--query--scope). Jika belum buat tutorial itu, guna `User::query()->latest()->limit(4)->pluck('name')`.

| Mode | Code | Siapa jalankan `handle()` | Page tunggu? |
| --- | --- | --- | --- |
| `queue` | `SayHello::dispatch($name)` | Worker (container `queue`) | Tidak |
| `sync` | `SayHello::dispatchSync($name)` | Request itu sendiri | **Ya, 3 saat** |
| `many` | `dispatch()` × 4 | Worker, satu demi satu | Tidak |
| `delay` | `dispatch($name)->delay(now()->addSeconds(20))` | Worker, selepas 20 saat | Tidak |

> `QUEUE_CONNECTION=sync` dalam `.env` jadikan **semua** `dispatch()` seperti `dispatchSync()`. Ia berguna untuk debug, dan juga digunakan dalam `phpunit.xml` semasa test.

## Route
`routes/web.php` (tambah `use`, dan route dalam group `auth` selepas `users.index`)
```php
use App\Http\Controllers\SayHelloController;

// ...

// Demo: run the SayHello job (queue, sync, many, delay)
Route::post('users/say-hello', SayHelloController::class)
    ->can('viewAny', User::class)
    ->name('users.say-hello');
```
Invokable controller didaftarkan dengan nama class sahaja, tanpa `[Controller::class, 'method']`.

## Button "Say hello" dalam page Users
`resources/views/users/index.blade.php`: tambah selepas `@endcan` button **Email all** (dan besarkan `max-w-lg` kepada `max-w-2xl` pada `<div>` yang membungkus):
```blade
<flux:dropdown position="bottom" align="end">
    <flux:button icon="queue-list" icon:trailing="chevron-down">{{ __('Say hello') }}</flux:button>

    <flux:menu>
        @foreach ([
            'queue' => __('Queue 1 job'),
            'sync' => __('Run now (sync, wait 3s)'),
            'many' => __('Queue 4 jobs'),
            'delay' => __('Queue with 20s delay'),
        ] as $mode => $label)
            <form method="POST" action="{{ route('users.say-hello') }}" class="w-full">
                @csrf
                <input type="hidden" name="mode" value="{{ $mode }}">
                <input type="hidden" name="search" value="{{ $search }}">

                <flux:menu.item as="button" type="submit" class="w-full cursor-pointer">
                    {{ $label }}
                </flux:menu.item>
            </form>
        @endforeach
    </flux:menu>
</flux:dropdown>
```
Setiap item ialah form `POST` kecil dengan `mode` yang berbeza. Ini cara yang sama dengan button **Log out** dalam menu user (starter kit).

## Buka 2 terminal
Terminal 1: lihat worker proses job
```bash
docker compose logs -f queue
```
Terminal 2: lihat log app (output `Log::info`)
```bash
docker compose exec app tail -f storage/logs/laravel.log
```

Login sebagai `admin@example.com` (atau mana-mana user yang ada role) dan buka **Users**.

## 1. Queue 1 job
Klik **Say hello → Queue 1 job**.
- Page **terus** kembali dengan mesej "1 job queued". Job hanya dimasukkan ke Redis.
- Terminal 1 (worker):
  ```
  queue-1  |   2026-10-07 13:05:19 App\Jobs\SayHello .............................. RUNNING
  queue-1  |   2026-10-07 13:05:22 App\Jobs\SayHello .............................. 3s DONE
  ```
- Terminal 2 (log):
  ```
  [2026-10-07 13:05:22] local.INFO: Hello, Admin User!
  ```

## 2. Run now (sync)
Klik **Say hello → Run now (sync, wait 3s)**.
Browser **loading 3 saat**, dan worker **tidak** nampak apa-apa. Job dijalankan terus dalam request.
Inilah yang berlaku jika tiada queue: user perlu tunggu kerja selesai.

## 3. Queue 4 jobs
Klik **Say hello → Queue 4 jobs**.
Page terus kembali. Dalam terminal worker, 4 job diproses **satu demi satu**, 3 saat setiap satu (jumlah ~12 saat). Log tunjuk "Hello, ..." untuk 4 user teratas dalam table.
Satu worker hanya jalankan satu job pada satu masa. Untuk lebih laju, tambah worker (lihat Sesi 5).

## 4. Queue with 20s delay
Klik **Say hello → Queue with 20s delay**.
Job masuk Redis sekarang, tetapi worker hanya ambil selepas 20 saat. Contoh guna sebenar: hantar email peringatan 1 jam selepas user daftar.

## Job menunggu dalam Redis
Stop worker:
```bash
docker compose stop queue
```
Klik **Queue 1 job** dua kali, dan **Queue with 20s delay** sekali. Page masih berfungsi walaupun worker mati.

Lihat dalam Redis:
```bash
docker compose exec redis redis-cli keys '*queues*'
# laravel-database-queues:default            ← job yang sedia dijalankan (list)
# laravel-database-queues:default:delayed    ← job yang ada delay (sorted set)

docker compose exec redis redis-cli llen laravel-database-queues:default            # 2
docker compose exec redis redis-cli zcard laravel-database-queues:default:delayed   # 1
```
> Prefix `laravel-database-` datang dari `APP_NAME` (lihat `config/database.php`, `redis.options.prefix`). Jika `APP_NAME` lain, nama key juga lain.

Hidupkan semula worker. Semua job yang menunggu terus diproses, dan **tiada job hilang**:
```bash
docker compose start queue
```

## Worker simpan code lama
Worker load code **sekali** semasa start dan simpan dalam memory.
1. Dalam `app/Jobs/SayHello.php`, tukar `Log::info("Hello, {$this->name}!");` kepada `Log::info("Selamat pagi, {$this->name}!");`
2. Klik **Run now (sync)** → log tulis **"Selamat pagi"** (request sentiasa guna code terkini).
3. Klik **Queue 1 job** → log masih tulis **"Hello"**, kerana worker guna code lama!
4. Jalankan `docker compose restart queue` dan klik **Queue 1 job** sekali lagi → baru **"Selamat pagi"**.

> **Penting:** selepas ubah code job (atau apa-apa yang job guna, seperti Mailable atau view email), sentiasa jalankan:
> ```bash
> docker compose restart queue
> ```

> **Tinker juga boleh:** semua cara di atas boleh dicuba tanpa page:
> ```bash
> docker compose exec app php artisan tinker --execute="App\Jobs\SayHello::dispatch('Ali');"
> ```

## Cuba sendiri
1. **Queue 1 job** → page terus kembali, `RUNNING`/`DONE` dalam log worker, dan "Hello, ..." dalam `laravel.log`.
2. Bandingkan masa: **Queue 1 job** (terus kembali) vs **Run now (sync)** (tunggu 3 saat).
3. **Queue 4 jobs**, kemudian lihat masa `DONE` setiap satu. Ia berselang 3 saat.
4. Search satu nama dalam table → **Queue 4 jobs** → log hanya ada nama yang padan dengan search.
5. Stop worker → klik **Queue 1 job** 3 kali → `llen` dalam Redis = 3 → start worker → `llen` = 0.
6. Login sebagai user **tanpa role** → tiada page Users. `POST /users/say-hello` → **403**.
7. Jalankan `docker compose exec redis redis-cli monitor` (lihat semua command Redis secara live), kemudian klik **Queue 1 job**. Anda nampak Laravel `rpush` job ke `laravel-database-queues:default` (dalam script `EVAL`), dan worker ambil job itu dengan `lpop`. Tekan `Ctrl+C` untuk berhenti.
8. Simpan cache (`Cache::put('x', 'yes', 600)` dalam tinker) → `docker compose restart redis` → `Cache::get('x')` masih `"yes"`, dan anda masih login. Redis simpan data ke volume `redis-data` sebelum berhenti.
   > ⚠️ `docker compose down -v` **padam semua volume** (Redis **dan** MariaDB). Jangan guna kecuali mahu mula dari kosong.

---

# Sesi 4 — Job & Queue: hantar email di background

Matlamat: guna apa yang kita belajar dalam Sesi 3 untuk email. Controller **tidak** hantar email sendiri. Ia masukkan satu **job** ke dalam queue dan terus redirect. Container `queue` hantar email di background.

> Konsep job, queue, worker, dan service `queue` sudah diterangkan dalam [Sesi 3](#sesi-3--job-pertama-faham-job-queue--worker).

## Cipta Job
```bash
docker compose exec app php artisan make:job SendUserMessage
```

`app/Jobs/SendUserMessage.php`
```php
<?php

namespace App\Jobs;

use App\Mail\UserMessage;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendUserMessage implements ShouldQueue
{
    use Queueable;

    /**
     * How many times to try the job before it is marked as failed.
     */
    public int $tries = 3;

    /**
     * Seconds to wait before trying again.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user,
        public string $subjectLine,
        public string $body,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Mail::to($this->user)->send(new UserMessage($this->user, $this->subjectLine, $this->body));
    }

    /**
     * Called once all tries have failed.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error("Could not email {$this->user->email}: {$exception?->getMessage()}");
    }
}
```

| Bahagian | Fungsi |
| --- | --- |
| `implements ShouldQueue` | Bila di-dispatch, job masuk ke Redis. Tanpa ini, job dijalankan terus. |
| `use Queueable` | Selain `delay()`, ia serialize model. Model `User` disimpan dalam Redis sebagai **ID sahaja**, dan worker ambil semula dari database. Jadi data user sentiasa terkini. |
| `$tries = 3` | Cuba sehingga 3 kali jika `handle()` throw exception (contoh SMTP down). |
| `$backoff = 10` | Tunggu 10 saat sebelum cuba lagi. |
| `failed()` | Dipanggil selepas semua cubaan gagal. Job disimpan dalam table `failed_jobs`. |

## Tukar controller: dispatch job
`app/Http/Controllers/UserEmailController.php`: ubah method `store()` dan `use`:
```php
use App\Jobs\SendUserMessage;   // ganti use App\Mail\UserMessage dan Mail

// ...

public function store(Request $request, User $user): RedirectResponse
{
    $validated = $request->validate([
        'subject' => ['required', 'string', 'max:150'],
        'body' => ['required', 'string', 'max:5000'],
    ]);

    SendUserMessage::dispatch($user, $validated['subject'], $validated['body']);

    return to_route('users.index')->with('status', __('Email queued for :name.', ['name' => $user->name]));
}
```

Restart worker supaya ia kenal job baru:
```bash
docker compose restart queue
docker compose logs -f queue
```

Hantar email dari page Users. Dalam log anda akan nampak:
```
queue-1  |   2026-10-03 15:08:14 App\Jobs\SendUserMessage ....................... RUNNING
queue-1  |   2026-10-03 15:08:14 App\Jobs\SendUserMessage ................. 212.77ms DONE
```
Browser terus redirect dengan mesej "Email queued for ...", dan email muncul dalam Mailpit sejurus selepas itu.

> **Cara ringkas:** jika hanya perlu hantar email, Laravel juga boleh queue Mailable terus:
> `Mail::to($user)->queue(new UserMessage(...))`, atau tambah `implements ShouldQueue` pada Mailable.
> Kita guna **Job sendiri** kerana ia lebih fleksibel: boleh tambah logic lain (log, update database, panggil API) dan set `$tries`/`$backoff`/`failed()` di satu tempat.

## Failed jobs: bila SMTP down
1. Matikan Mailpit (anggap server email down):
   ```bash
   docker compose stop mailpit
   ```
2. Hantar email dari page Users. Dalam `docker compose logs -f queue`, job **FAIL** dan dicuba semula setiap 10 saat, 3 kali.
3. Lihat job yang gagal:
   ```bash
   docker compose exec app php artisan queue:failed
   ```
   Log error dari `failed()` ada dalam `storage/logs/laravel.log`.
4. Hidupkan semula Mailpit dan cuba lagi:
   ```bash
   docker compose start mailpit
   docker compose exec app php artisan queue:retry all
   ```
   Email sampai dalam Mailpit.

Bandingkan dengan Sesi 2: tanpa queue, user akan nampak **error 500** dan email hilang. Dengan queue, user tidak terganggu dan email boleh dihantar semula.

## Cuba sendiri
1. Hantar email → mesej "Email queued", log `DONE`, dan email ada dalam Mailpit.
2. Stop container queue (`docker compose stop queue`) → hantar 2 email → tiada dalam Mailpit (job menunggu dalam Redis). `docker compose start queue` → kedua-dua email sampai.
3. Ubah teks dalam `mail/user-message.blade.php` **tanpa** restart queue → email masih guna teks lama? Jalankan `docker compose restart queue` dan cuba lagi.

---

# Sesi 5 — Hantar email kepada ramai user (bulk)

Matlamat: button **Email all** di atas table. Ia hantar email kepada semua user yang padan dengan **search** semasa (kecuali diri sendiri). Satu job untuk setiap user, jadi request kekal laju walaupun ada ribuan user.

## Policy
`app/Policies/UserPolicy.php` (tambah)
```php
/**
 * Can the user send an email to many users at once?
 */
public function emailAny(User $user): bool
{
    return $user->can('email users');
}
```

## Route
`routes/web.php`: letak **sebelum** route `users/{user}/...`
```php
// Bulk email - keep these above the users/{user} routes
Route::get('users/email', [UserEmailController::class, 'createBulk'])
    ->can('emailAny', User::class)
    ->name('users.email-bulk.create');

Route::post('users/email', [UserEmailController::class, 'storeBulk'])
    ->can('emailAny', User::class)
    ->name('users.email-bulk.store');
```
> Kenapa sebelum? Laravel padankan route ikut susunan. Jika ada route seperti `users/{user}` di atas, `users/email` boleh disangka `{user} = "email"` dan anda dapat 404.

## Controller (versi penuh)
`app/Http/Controllers/UserEmailController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Jobs\SendUserMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserEmailController extends Controller
{
    /**
     * Show the "Send email" form for one user.
     */
    public function create(User $user): View
    {
        return view('users.email', [
            'user' => $user,
            'search' => null,
            'count' => 1,
        ]);
    }

    /**
     * Queue an email to one user.
     */
    public function store(Request $request, User $user): RedirectResponse
    {
        $validated = $this->validateMessage($request);

        SendUserMessage::dispatch($user, $validated['subject'], $validated['body']);

        return to_route('users.index')->with('status', __('Email queued for :name.', ['name' => $user->name]));
    }

    /**
     * Show the "Send email" form for all users (matching the search).
     */
    public function createBulk(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        return view('users.email', [
            'user' => null,
            'search' => $search,
            'count' => $this->recipients($request, $search)->count(),
        ]);
    }

    /**
     * Queue one email per user (matching the search).
     */
    public function storeBulk(Request $request): RedirectResponse
    {
        $validated = $this->validateMessage($request);
        $search = $request->string('search')->trim()->toString();

        $count = 0;

        $this->recipients($request, $search)->each(function (User $user) use ($validated, &$count) {
            SendUserMessage::dispatch($user, $validated['subject'], $validated['body']);
            $count++;
        });

        return to_route('users.index', ['search' => $search ?: null])
            ->with('status', __(':count emails queued.', ['count' => $count]));
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function validateMessage(Request $request): array
    {
        return $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
    }

    /**
     * Users to email: everyone matching the search, except the sender.
     *
     * @return Builder<User>
     */
    private function recipients(Request $request, string $search): Builder
    {
        return User::query()
            ->whereKeyNot($request->user()->getKey())
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }));
    }
}
```
- `recipients()` guna search yang **sama** seperti `UserController@index`, jadi user yang dihantar email ialah user yang anda nampak dalam table.
- `->each()` ambil user secara berkelompok (chunk 1000), jadi memory tidak penuh walaupun ada ramai user.
- Controller hanya **dispatch**. Kerja berat (SMTP) dibuat oleh worker.

## View form (versi penuh, untuk satu user dan bulk)
`resources/views/users/email.blade.php`
```blade
<x-layouts::app :title="__('Send email')">
    <div class="max-w-xl">
        <flux:heading size="xl" level="1">{{ __('Send email') }}</flux:heading>

        @if ($user)
            <flux:subheading class="mb-6">{{ __('To') }}: {{ $user->name }} ({{ $user->email }})</flux:subheading>
        @else
            <flux:subheading class="mb-6">
                {{ __('To :count users', ['count' => $count]) }}
                @if ($search)
                    ({{ __('matching ":search"', ['search' => $search]) }})
                @endif
            </flux:subheading>
        @endif

        <form method="POST"
              action="{{ $user ? route('users.email.store', $user) : route('users.email-bulk.store') }}"
              class="space-y-6">
            @csrf

            @unless ($user)
                <input type="hidden" name="search" value="{{ $search }}">
            @endunless

            <flux:input name="subject" :label="__('Subject')" :value="old('subject')" required />

            <flux:textarea name="body" :label="__('Message')" rows="6" required>{{ old('body') }}</flux:textarea>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary" icon="paper-airplane">{{ __('Send') }}</flux:button>
                <flux:button :href="route('users.index', ['search' => $search ?: null])" variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
```

## Button "Email all" dalam page Users
`resources/views/users/index.blade.php`: ganti `<form method="GET" ...>` search dalam header dengan:
```blade
<div class="flex w-full max-w-lg items-center justify-end gap-2">
    <form method="GET" action="{{ route('users.index') }}" class="flex w-full gap-2">
        <flux:input name="search" :value="$search" icon="magnifying-glass" :placeholder="__('Search name or email')" />
        <flux:button type="submit">{{ __('Search') }}</flux:button>
    </form>

    @can('emailAny', App\Models\User::class)
        <flux:button variant="primary" icon="envelope" :href="route('users.email-bulk.create', ['search' => $search ?: null])">
            {{ __('Email all') }}
        </flux:button>
    @endcan
</div>
```
Link bawa `search` semasa, jadi form tahu siapa penerimanya.

Restart worker (code berubah):
```bash
docker compose restart queue
```

## Cuba sendiri
1. Login `admin@example.com` → **Users** → search sesuatu (contoh nama keluarga yang muncul beberapa kali) → **Email all**. Subheading tunjuk "To N users (matching ...)".
2. **Send** → terus kembali ke table dengan mesej "N emails queued." Dalam `docker compose logs -f queue`, job `SendUserMessage` diproses satu-satu. Mailpit terima N email.
3. **Email all** tanpa search → semua user kecuali diri sendiri.
4. Login `staff@example.com` → tiada button **Email all**. Buka `http://localhost:8000/users/email` → **403**.
5. Cuba jalankan 2 worker serentak untuk proses lebih cepat:
   ```bash
   docker compose up -d --scale queue=2
   ```
   (Kembalikan dengan `--scale queue=1`.)

## Test
Projek ini ada test dalam `tests/Feature/UserEmailTest.php`. Ia guna `Queue::fake()` dan `Mail::fake()` supaya tiada email betul dihantar semasa test:
```php
test('admin can queue an email to a user', function () {
    Queue::fake();

    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('users.email.store', $user), ['subject' => 'Hello', 'body' => 'Welcome!'])
        ->assertRedirect(route('users.index'));

    Queue::assertPushed(SendUserMessage::class, fn ($job) => $job->user->is($user) && $job->subjectLine === 'Hello');
});

test('the job sends the mailable', function () {
    Mail::fake();

    $user = User::factory()->create();

    (new SendUserMessage($user, 'Hello', 'Welcome!'))->handle();

    Mail::assertSent(UserMessage::class, fn ($mail) => $mail->hasTo($user->email) && $mail->subjectLine === 'Hello');
});
```
```bash
docker compose exec app php artisan test --filter=UserEmail
```

---

## Ringkasan
| Lapisan | Guna | Fungsi |
| --- | --- | --- |
| Mailpit | service `mailpit`, `MAIL_HOST=mailpit` | Tangkap semua email untuk development, dan lihat di http://localhost:8025 |
| Job pertama | `SayHello` + `dispatch()` / `dispatchSync()` / `delay()` | Faham bagaimana job masuk ke Redis dan dijalankan oleh worker |
| Mailable | `UserMessage` + `mail/user-message.blade.php` | **Isi** email: subject, view markdown, data |
| Job | `SendUserMessage implements ShouldQueue` | **Kerja** hantar email di background, dengan `$tries`, `$backoff`, `failed()` |
| Redis | service `redis`, `REDIS_HOST=redis`, volume `redis-data` | Simpan queue, session dan cache. `app` tunggu Redis **healthy** sebelum start. |
| Queue | `QUEUE_CONNECTION=redis` + service `queue` (`queue:work`) | Simpan job dan jalankan oleh worker. Restart selepas ubah code. |
| Policy | `email()`, `emailAny()` + route `->can()` + `@can` | Hanya role dengan permission `email users` boleh hantar |

## Command berguna
```bash
docker compose exec redis redis-cli ping                       # PONG = Redis hidup
docker compose exec redis redis-cli monitor                    # lihat semua command Redis secara live
docker compose exec redis redis-cli info keyspace              # bilangan key setiap database
docker compose exec app php artisan about --only=drivers       # semak cache/queue/session guna redis
docker compose logs -f queue                                   # lihat job diproses
docker compose exec app tail -f storage/logs/laravel.log       # lihat output Log::info dari job
docker compose exec redis redis-cli llen laravel-database-queues:default   # bilangan job menunggu
docker compose restart queue                                   # WAJIB selepas ubah code job/mail
docker compose exec app php artisan queue:failed               # senarai job gagal
docker compose exec app php artisan queue:retry all            # cuba semula semua job gagal
docker compose exec app php artisan queue:retry <uuid>         # cuba semula satu job
docker compose exec app php artisan queue:forget <uuid>        # buang satu job gagal
docker compose exec app php artisan queue:flush                # buang semua job gagal
docker compose exec app php artisan queue:clear                # kosongkan job yang sedang menunggu dalam queue
docker compose exec app php artisan queue:monitor redis:default --max=100   # amaran jika queue terlalu panjang
docker compose up -d --scale queue=2                           # jalankan 2 worker
```
