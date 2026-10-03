# Tutorial Access Permission & Policy (Spatie laravel-permission)

Tutorial ini sambung dari [Readme.md](Readme.md) — app Laravel 13 yang berjalan dalam Docker (MariaDB + Redis).
Kita guna cara Laravel biasa: **Route → Controller → Blade view** dengan form HTML (tanpa Livewire).
Semua command `php artisan` / `composer` dijalankan **dalam container** guna `docker compose exec app ...`.

- [Sesi 1 — Senarai table user](#sesi-1--senarai-table-user)
- [Sesi 2 — Install Spatie, cipta role & permission](#sesi-2--install-spatie-cipta-role--permission)
- [Sesi 3 — Policy: siapa boleh buka page Users](#sesi-3--policy-siapa-boleh-buka-page-users)
- [Sesi 4 — Policy pada action: Edit role & Delete](#sesi-4--policy-pada-action-edit-role--delete)

### Gambaran keseluruhan
```
User ──has──► Role ──has──► Permission          (Spatie: simpan dalam database)
                                  │
                                  ▼
                      UserPolicy guna $user->can('view users')   (Laravel: logic "siapa boleh buat apa")
                                  │
              ┌───────────────────┴──────────────────────┐
              ▼                                          ▼
               Route ->can()                       @can dalam Blade
        (block URL — 403 sebelum controller)      (sorok button/link)
```

| Role | Permission | Boleh buat |
| --- | --- | --- |
| `super-admin` | `view users`, `create users`, `edit users`, `delete users` | Sama seperti admin, **dan** boleh beri role `super-admin` |
| `admin` | `view users`, `create users`, `edit users`, `delete users` | Lihat, tukar role, delete user biasa |
| `staff` | `view users` | Lihat senarai sahaja |
| (tiada role) | — | Tidak boleh buka page Users |

> **Nota:** view guna component `<flux:...>` (contoh `<flux:table>`, `<flux:button>`) yang sudah ada dalam starter kit.
> Ia hanya **Blade component** biasa untuk UI — boleh diganti dengan HTML + Tailwind biasa jika mahu.

---

# Sesi 1 — Senarai table user

Matlamat: page `/users` yang tunjuk semua user dalam table, dengan search dan pagination. **Belum ada permission lagi** — semua user yang login boleh lihat.

## Cipta controller
```bash
docker compose exec app php artisan make:controller UserController
```

`app/Http/Controllers/UserController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * List all users, with search and pagination.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        $users = User::query()
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }
}
```
- `$request->string('search')->trim()->toString()` — ambil `?search=...` dari URL, buang space, default string kosong.
- `->when($search, ...)` — tambah `where` hanya bila ada search. Search dibungkus dalam `where(function ...)` supaya `orWhere` tidak "bocor" ke condition lain.
- `->paginate(10)` — 10 user setiap page.
- `->withQueryString()` — link pagination kekalkan `?search=...` (contoh `/users?search=ali&page=2`).

## Cipta view
`resources/views/users/index.blade.php`
```blade
<x-layouts::app :title="__('Users')">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('All registered users') }}</flux:subheading>
        </div>

        <form method="GET" action="{{ route('users.index') }}" class="flex w-full max-w-sm gap-2">
            <flux:input name="search" :value="$search" icon="magnifying-glass" :placeholder="__('Search name or email')" />
            <flux:button type="submit">{{ __('Search') }}</flux:button>
        </form>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Verified') }}</flux:table.column>
            <flux:table.column>{{ __('Joined') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($users as $user)
                <flux:table.row>
                    <flux:table.cell class="flex items-center gap-3">
                        <flux:avatar size="xs" :name="$user->name" :initials="$user->initials()" />
                        {{ $user->name }}
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->email }}</flux:table.cell>

                    <flux:table.cell>
                        @if ($user->email_verified_at)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Yes') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('No') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->created_at->format('d M Y') }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center">{{ __('No users found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</x-layouts::app>
```
- `<x-layouts::app>` — layout yang sama dengan page Dashboard (sidebar, header).
- Form search guna `method="GET"`, jadi search masuk dalam URL (`/users?search=ali`) dan boleh di-bookmark.
- `@forelse ... @empty` — seperti `@foreach`, tetapi ada bahagian bila tiada data.
- `{{ $users->links() }}` — link pagination dari Laravel.

## Route
`routes/web.php`
```php
<?php

use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
});

require __DIR__.'/settings.php';
```
Route berada dalam group `auth` + `verified`, jadi hanya user yang login (dan email verified) boleh buka.

```bash
docker compose exec app php artisan route:list --path=users
```

## Link dalam sidebar
`resources/views/layouts/app/sidebar.blade.php` — tambah selepas item **Dashboard**:
```blade
<flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>
    {{ __('Users') }}
</flux:sidebar.item>
```

## Data contoh
`database/seeders/DatabaseSeeder.php` — tukar baris `// User::factory(10)->create();` kepada:
```php
User::factory(25)->create();
```
```bash
docker compose exec app php artisan db:seed
```

## Cuba sendiri
Login dan buka http://localhost:8000/users. Cuba search nama atau email, dan tukar page — perhatikan URL berubah.

> **Masalah:** sekarang **semua** user yang login boleh lihat senarai semua user. Sesi seterusnya kita betulkan.

---

# Sesi 2 — Install Spatie, cipta role & permission

Matlamat: simpan **role** dan **permission** dalam database guna [spatie/laravel-permission](https://spatie.be/docs/laravel-permission), dan tunjuk role setiap user dalam table.

## Konsep
- **Permission** — satu tindakan kecil, contoh `view users`, `delete users`.
- **Role** — kumpulan permission, contoh `admin` = semua permission user.
- User diberi **role** (atau permission terus). Kita semak **permission**, bukan role — jadi bila role berubah, code tidak perlu diubah.

## Install package
```bash
docker compose exec app composer require spatie/laravel-permission
```

## Publish config dan migration
```bash
docker compose exec app php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
```
Ini cipta:
- `config/permission.php`
- `database/migrations/xxxx_xx_xx_xxxxxx_create_permission_tables.php`

```bash
docker compose exec app php artisan migrate
```
Table baru:

| Table | Simpan apa |
| --- | --- |
| `roles` | Senarai role |
| `permissions` | Senarai permission |
| `role_has_permissions` | Permission mana milik role mana |
| `model_has_roles` | User mana ada role apa |
| `model_has_permissions` | Permission yang diberi terus kepada user (tanpa role) |

## Tambah trait `HasRoles` pada User
`app/Models/User.php`
```php
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;
```
Trait ini bagi method seperti `assignRole()`, `syncRoles()`, `hasRole()`, `givePermissionTo()` dan relation `roles`.
Ia juga daftar semua permission ke dalam Laravel Gate, jadi `$user->can('view users')` terus berfungsi.

## Seeder role & permission
```bash
docker compose exec app php artisan make:seeder RolePermissionSeeder
```
`database/seeders/RolePermissionSeeder.php`
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the roles and permissions.
     */
    public function run(): void
    {
        // Clear the cached roles and permissions before changing them
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'view users',
            'create users',
            'edit users',
            'delete users',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        // Clear the cache again so the new permissions can be found by name.
        // (DatabaseSeeder uses WithoutModelEvents, which stops Spatie clearing it automatically.)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::findOrCreate('super-admin')->syncPermissions($permissions);

        Role::findOrCreate('admin')->syncPermissions($permissions);

        Role::findOrCreate('staff')->syncPermissions(['view users']);
    }
}
```
- `findOrCreate()` — cipta jika belum wujud. Seeder boleh dijalankan berulang kali tanpa error.
- `syncPermissions()` — set permission role kepada senarai ini tepat-tepat (buang yang lain).

> **Gotcha — cache:** Spatie cache semua permission (dalam Redis untuk projek ini). `DatabaseSeeder` guna trait
> `WithoutModelEvents`, yang matikan event "clear cache" Spatie. Tanpa `forgetCachedPermissions()` kedua,
> anda akan dapat error `There is no permission named 'view users' for guard 'web'`.

## Update DatabaseSeeder
`database/seeders/DatabaseSeeder.php`
```php
public function run(): void
{
    $this->call(RolePermissionSeeder::class);

    User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
    ])->assignRole('super-admin');

    User::factory()->create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
    ])->assignRole('admin');

    User::factory()->create([
        'name' => 'Staff User',
        'email' => 'staff@example.com',
    ])->assignRole('staff');

    // Users without any role
    User::factory(25)->create();
}
```
Password semua user dari factory ialah `password`.

```bash
docker compose exec app php artisan migrate:fresh --seed
docker compose exec app php artisan permission:show
```
`permission:show` tunjuk matrix role vs permission:
```
+--------------+-------+-------+-------------+
|              | admin | staff | super-admin |
+--------------+-------+-------+-------------+
| create users |  ✔    |  ·    |  ✔          |
| delete users |  ✔    |  ·    |  ✔          |
| edit users   |  ✔    |  ·    |  ✔          |
| view users   |  ✔    |  ✔    |  ✔          |
+--------------+-------+-------+-------------+
```

## Tunjuk role dalam table
`app/Http/Controllers/UserController.php` — tambah `->with('roles')` dalam query:
```php
$users = User::query()
    ->with('roles')
    ->when(...)
```
`with('roles')` = eager loading. Tanpa ini, setiap row buat 1 query tambahan untuk ambil role (masalah N+1).

`resources/views/users/index.blade.php` — tambah column selepas **Email**:
```blade
<flux:table.column>{{ __('Roles') }}</flux:table.column>
```
dan cell:
```blade
<flux:table.cell>
    @forelse ($user->roles as $role)
        <flux:badge size="sm" color="blue" inset="top bottom">{{ $role->name }}</flux:badge>
    @empty
        <flux:text>-</flux:text>
    @endforelse
</flux:table.cell>
```
Tukar `colspan="4"` kepada `colspan="5"`.

## Cuba dalam tinker
```bash
docker compose exec app php artisan tinker
```
```php
$staff = User::where('email', 'staff@example.com')->first();
$staff->hasRole('staff');          // true
$staff->can('view users');         // true
$staff->can('delete users');       // false
$staff->getAllPermissions()->pluck('name');
```

> **Masih ada masalah:** kita sudah ada role & permission, tetapi page `/users` **belum semak** apa-apa. Itu kerja Policy.

---

# Sesi 3 — Policy: siapa boleh buka page Users

Matlamat: hanya user dengan permission `view users` boleh buka page Users dan nampak link dalam sidebar.

## Kenapa Policy?
Permission jawab soalan "user ini ada kebenaran **apa**?". Policy jawab "boleh user ini buat tindakan ini pada **record ini**?".
Contoh: admin ada `delete users`, tetapi tetap **tidak boleh** delete super-admin atau diri sendiri. Peraturan seperti ini diletakkan dalam Policy.

## Cipta Policy
```bash
docker compose exec app php artisan make:policy UserPolicy --model=User
```
`app/Policies/UserPolicy.php`
```php
<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Can the user open the users list?
     */
    public function viewAny(User $user): bool
    {
        return $user->can('view users');
    }

    /**
     * Can the user view this user?
     */
    public function view(User $user, User $model): bool
    {
        return $user->can('view users');
    }

    /**
     * Can the user create users?
     */
    public function create(User $user): bool
    {
        return $user->can('create users');
    }

    /**
     * Can the user edit this user (e.g. change their role)?
     * A super-admin cannot be edited here.
     */
    public function update(User $user, User $model): bool
    {
        return $user->can('edit users')
            && ! $model->hasRole('super-admin');
    }

    /**
     * Can the user delete this user?
     * Nobody can delete themselves, and a super-admin cannot be deleted here.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->can('delete users')
            && $user->isNot($model)
            && ! $model->hasRole('super-admin');
    }
}
```
- `$user` — user yang sedang login. `$model` — user yang hendak dilihat / diubah.
- Laravel jumpa `UserPolicy` untuk model `User` secara automatik (nama ikut convention), tidak perlu daftar.
- Policy semak **permission** (`can('edit users')`), bukan nama role. Jika esok role `manager` diberi `edit users`, ia terus berfungsi.
- Method `restore` dan `forceDelete` yang dijana oleh `make:policy` boleh dibuang — kita tidak guna soft delete.

## Lindungi route
`routes/web.php`
```php
use App\Models\User;

Route::get('users', [UserController::class, 'index'])
    ->can('viewAny', User::class)
    ->name('users.index');
```
`->can('viewAny', User::class)` = middleware `can:viewAny,App\Models\User`. Laravel panggil `UserPolicy::viewAny()` sebelum controller dijalankan.
User tanpa kebenaran dapat **403 Forbidden**.

## Sorok link dalam sidebar
`resources/views/layouts/app/sidebar.blade.php`
```blade
@can('viewAny', App\Models\User::class)
    <flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.*')" wire:navigate>
        {{ __('Users') }}
    </flux:sidebar.item>
@endcan
```

> **Penting:** `@can` hanya **sorok** link — ia bukan security. Jika user taip `/users` terus dalam browser,
> yang block ialah route `->can()`. Sentiasa lindungi di server.

## Cuba sendiri
| Login sebagai | Password | Link Users dalam sidebar | Buka `/users` |
| --- | --- | --- | --- |
| `test@example.com` (super-admin) | `password` | Ada | OK |
| `admin@example.com` (admin) | `password` | Ada | OK |
| `staff@example.com` (staff) | `password` | Ada | OK |
| User lain (tiada role) | `password` | Tiada | 403 |

Cuba juga dalam tinker:
```php
$admin = User::where('email', 'admin@example.com')->first();
$super = User::where('email', 'test@example.com')->first();
$admin->can('viewAny', User::class);   // true  — UserPolicy::viewAny
$admin->can('delete', $super);         // false — target ialah super-admin
$admin->can('delete', $admin);         // false — diri sendiri
$super->can('delete', $admin);         // true
$super->can('delete', $super);         // false — policy sama untuk semua, termasuk super-admin
```

---

# Sesi 4 — Policy pada action: Edit role & Delete

Matlamat: tambah button **Edit role** dan **Delete** pada setiap row. Button hanya muncul jika policy benarkan, **dan** setiap route semak policy dengan `->can()`.

## Peraturan
| Login sebagai | Edit role | Delete |
| --- | --- | --- |
| super-admin | Semua user kecuali super-admin (boleh beri role `super-admin`) | Semua user kecuali super-admin |
| admin | User biasa sahaja — bukan super-admin; tidak boleh beri role `super-admin` | User biasa sahaja — bukan super-admin, bukan diri sendiri |
| staff | Tiada | Tiada |

## Route
`routes/web.php` — tambah dalam group `auth`:
```php
Route::get('users/{user}/edit', [UserController::class, 'edit'])
    ->can('update', 'user')
    ->name('users.edit');

Route::put('users/{user}', [UserController::class, 'update'])
    ->can('update', 'user')
    ->name('users.update');

Route::delete('users/{user}', [UserController::class, 'destroy'])
    ->can('delete', 'user')
    ->name('users.destroy');
```
- `{user}` = **route model binding**. Laravel cari `User` dengan id tersebut dan hantar ke controller. Jika tiada → 404.
- `->can('update', 'user')` = middleware `can:update,user`. `'user'` ialah **nama parameter** `{user}` (string), bukan class.
  Laravel ambil `User` dari route model binding dan panggil `UserPolicy::update(auth()->user(), $user)`.
- Jika policy return `false` → **403**, dan controller **tidak dijalankan langsung**.
- `edit` **dan** `update` kedua-duanya ada `->can()`. Seseorang boleh hantar form `PUT` terus (contoh guna Postman) tanpa buka page edit.

Bandingkan dengan route `users.index` dari Sesi 3:

| Route | `->can(...)` | Kenapa |
| --- | --- | --- |
| `users.index` | `->can('viewAny', User::class)` | Tiada record tertentu — hantar **class** |
| `users.edit`, `users.update` | `->can('update', 'user')` | Semak pada **record** `{user}` — hantar nama parameter |
| `users.destroy` | `->can('delete', 'user')` | Sama seperti di atas |

Semak middleware pada setiap route:
```bash
docker compose exec app php artisan route:list --path=users -v
```
Setiap route sepatutnya ada baris `⇂ Illuminate\Auth\Middleware\Authorize:update,user` (atau `delete,user` / `viewAny,App\Models\User`).

## Controller (versi penuh)
`app/Http/Controllers/UserController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * List all users, with search and pagination.
     */
    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();

        $users = User::query()
            ->with('roles')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
        ]);
    }

    /**
     * Show the "Edit role" form.
     */
    public function edit(Request $request, User $user): View
    {
        return view('users.edit', [
            'user' => $user,
            'roles' => $this->assignableRoles($request->user()),
        ]);
    }

    /**
     * Save the new role.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', Rule::in($this->assignableRoles($request->user()))],
        ]);

        $user->syncRoles([$validated['role']]);

        return to_route('users.index')->with('status', __('Role updated.'));
    }

    /**
     * Delete a user.
     */
    public function destroy(User $user): RedirectResponse
    {
        $user->delete();

        return to_route('users.index')->with('status', __('User deleted.'));
    }

    /**
     * Roles the current user is allowed to give.
     * Only a super-admin can make someone else a super-admin.
     *
     * @return array<int, string>
     */
    private function assignableRoles(User $user): array
    {
        return Role::query()
            ->when(! $user->hasRole('super-admin'), fn ($query) => $query->where('name', '!=', 'super-admin'))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }
}
```
Perkara penting:
- Controller **tiada** semakan policy — route `->can()` sudah semak sebelum controller dijalankan. Controller hanya fokus pada kerja (validate, simpan, redirect).
- `Rule::in($this->assignableRoles(...))` — admin tidak nampak role `super-admin` dalam dropdown, dan jika dia hantar nilai itu secara manual, validation gagal.
- `syncRoles([...])` — buang semua role lama dan set role baru.
- `to_route(...)->with('status', ...)` — redirect ke senarai dengan flash message (dipaparkan sekali sahaja).

> **Cara lain:** semak dalam controller guna `Gate::authorize('update', $user);` pada baris pertama method.
> Hasilnya sama (403). Dalam tutorial ini kita guna `->can()` pada route supaya semua semakan nampak di satu tempat (`routes/web.php`).

## View senarai (versi penuh)
`resources/views/users/index.blade.php`
```blade
<x-layouts::app :title="__('Users')">
    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Users') }}</flux:heading>
            <flux:subheading>{{ __('All registered users') }}</flux:subheading>
        </div>

        <form method="GET" action="{{ route('users.index') }}" class="flex w-full max-w-sm gap-2">
            <flux:input name="search" :value="$search" icon="magnifying-glass" :placeholder="__('Search name or email')" />
            <flux:button type="submit">{{ __('Search') }}</flux:button>
        </form>
    </div>

    @if (session('status'))
        <flux:callout variant="success" icon="check-circle" :heading="session('status')" class="mb-6" />
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Roles') }}</flux:table.column>
            <flux:table.column>{{ __('Verified') }}</flux:table.column>
            <flux:table.column>{{ __('Joined') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($users as $user)
                <flux:table.row>
                    <flux:table.cell class="flex items-center gap-3">
                        <flux:avatar size="xs" :name="$user->name" :initials="$user->initials()" />
                        {{ $user->name }}
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->email }}</flux:table.cell>

                    <flux:table.cell>
                        @forelse ($user->roles as $role)
                            <flux:badge size="sm" color="blue" inset="top bottom">{{ $role->name }}</flux:badge>
                        @empty
                            <flux:text>-</flux:text>
                        @endforelse
                    </flux:table.cell>

                    <flux:table.cell>
                        @if ($user->email_verified_at)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Yes') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('No') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell>{{ $user->created_at->format('d M Y') }}</flux:table.cell>

                    <flux:table.cell align="end">
                        <div class="flex justify-end gap-1">
                            @can('update', $user)
                                <flux:button size="sm" variant="ghost" icon="shield-check" :href="route('users.edit', $user)">
                                    {{ __('Edit role') }}
                                </flux:button>
                            @endcan

                            @can('delete', $user)
                                <form method="POST" action="{{ route('users.destroy', $user) }}"
                                      onsubmit="return confirm(@js(__('Delete :name?', ['name' => $user->name])))">
                                    @csrf
                                    @method('DELETE')

                                    <flux:button size="sm" variant="ghost" icon="trash" type="submit">
                                        {{ __('Delete') }}
                                    </flux:button>
                                </form>
                            @endcan
                        </div>
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center">{{ __('No users found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-4">
        {{ $users->links() }}
    </div>
</x-layouts::app>
```
- `@can('update', $user)` — Blade panggil `UserPolicy::update(auth()->user(), $user)` untuk row ini.
  Untuk row anda sendiri, `UserPolicy::delete()` return `false` (`$user->isNot($model)`), jadi button Delete tidak muncul.
- Delete guna **form** kecil kerana HTML link hanya boleh buat `GET`:
  - `@csrf` — token CSRF, wajib untuk semua form `POST`.
  - `@method('DELETE')` — HTML form hanya ada `GET`/`POST`, jadi Laravel guna hidden field `_method` untuk `DELETE` / `PUT`.
  - `onsubmit="return confirm(...)"` — browser tanya "Delete ...?" dahulu. `@js()` escape nama dengan selamat (contoh nama `O'Kon` yang ada `'`).
- `session('status')` — flash message dari `->with('status', ...)` dalam controller.

## View edit role
`resources/views/users/edit.blade.php`
```blade
<x-layouts::app :title="__('Edit role')">
    <div class="max-w-md">
        <flux:heading size="xl" level="1">{{ __('Edit role') }}</flux:heading>
        <flux:subheading class="mb-6">{{ $user->name }} ({{ $user->email }})</flux:subheading>

        <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <flux:select name="role" :label="__('Role')">
                @foreach ($roles as $role)
                    <flux:select.option :value="$role" :selected="old('role', $user->roles->first()?->name) === $role">
                        {{ $role }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button :href="route('users.index')" variant="ghost">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    </div>
</x-layouts::app>
```
- `$roles` datang dari `assignableRoles()` — admin hanya nampak `admin` dan `staff`.
- `old('role', ...)` — jika validation gagal, pilihan user tadi kekal. Jika tiada, guna role semasa user.
- `<flux:select name="role">` paparkan error validation untuk `role` secara automatik di bawah dropdown.

## Cuba sendiri
1. Login `admin@example.com` / `password` → buka **Users**.
   - Ada **Edit role** dan **Delete** pada user biasa, tetapi **tiada** pada Test User (super-admin), dan tiada Delete pada row sendiri.
   - Klik **Edit role** → dropdown hanya ada `admin` dan `staff`. Tukar ke `staff` → Save → mesej "Role updated."
   - Klik **Delete** pada user biasa → confirm → mesej "User deleted."
2. Masih sebagai admin, taip URL edit Test User terus: `http://localhost:8000/users/1/edit` → **403**. Button disorok, tetapi controller tetap block.
3. Login `staff@example.com` → table sahaja, tiada button. Buka `http://localhost:8000/users/5/edit` → **403**.
4. Login `test@example.com` (super-admin) → button pada semua row kecuali row super-admin (termasuk row sendiri). Dropdown role ada `super-admin`.

---

## Ringkasan
| Lapisan | Guna | Fungsi |
| --- | --- | --- |
| Spatie Role & Permission | `assignRole()`, `syncPermissions()`, `$user->can('view users')` | Simpan "siapa ada kebenaran apa" dalam database |
| Policy | `UserPolicy` | Peraturan per record (bukan diri sendiri, bukan super-admin) |
| Route | `->can('viewAny', User::class)`, `->can('delete', 'user')` | Block URL & action — 403 sebelum controller (walaupun request dihantar terus) |
| Blade | `@can('delete', $user)` | Sorok button/link sahaja — **bukan** security |

## Command berguna
```bash
docker compose exec app php artisan permission:show            # matrix role vs permission
docker compose exec app php artisan permission:cache-reset     # clear cache permission
docker compose exec app php artisan permission:create-role editor
docker compose exec app php artisan permission:create-permission "export users"
docker compose exec app php artisan permission:assign-role admin 2   # beri role admin kepada user id 2
```
