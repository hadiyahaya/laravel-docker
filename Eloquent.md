# Tutorial Eloquent ORM

Tutorial ini sambung dari [PermissionPolicy.md](PermissionPolicy.md) dan [JobAndMail.md](JobAndMail.md). Kita guna app yang sama (Users page) dan tambah 2 model baru: **Department** dan **Project**.
Banyak contoh dicuba dalam **tinker** dahulu, kemudian digunakan dalam page sebenar (**Route → Controller → Blade view**).
Semua command `php artisan` dijalankan **dalam container** guna `docker compose exec app ...`.

- [Sesi 1 — Model, Migration, Factory & Seeder](#sesi-1--model-migration-factory--seeder)
- [Sesi 2 — Query & Scope](#sesi-2--query--scope)
- [Sesi 3 — Relationship One-to-Many & Eager Loading](#sesi-3--relationship-one-to-many--eager-loading)
- [Sesi 4 — Relationship Many-to-Many (pivot)](#sesi-4--relationship-many-to-many-pivot)
- [Sesi 5 — Casts, Enum, Accessor/Mutator & Soft Delete](#sesi-5--casts-enum-accessormutator--soft-delete)

### Gambaran keseluruhan
```
departments                users                          projects
┌────────────┐  1     *  ┌─────────────────┐  *      *  ┌──────────────┐
│ id         │◄─────────│ department_id   │            │ id           │
│ name       │ hasMany   │ id, name, email │            │ name         │
│ code       │ belongsTo │ ...             │            │ status (enum)│
│ is_active  │           └────────┬────────┘            │ deadline     │
└────────────┘                    │                     │ deleted_at   │
                                  │   project_user      └──────┬───────┘
                                  │  ┌──────────────┐          │
                                  └─►│ user_id      │◄─────────┘
                                     │ project_id   │   belongsToMany
                                     │ role         │   (lead / member)
                                     └──────────────┘
```

| Model | Relationship | Contoh |
| --- | --- | --- |
| `Department` | `hasMany(User)` | `$department->users` |
| `User` | `belongsTo(Department)` | `$user->department->name` |
| `User` | `belongsToMany(Project)` | `$user->projects` |
| `Project` | `belongsToMany(User)` | `$project->users()->attach($user, ['role' => 'lead'])` |

> **Tinker:** semua contoh `>` di bawah ditaip dalam tinker:
> ```bash
> docker compose exec app php artisan tinker
> ```
> Keluar dengan `exit`. Selepas ubah code model, keluar dan masuk semula tinker supaya code baru di-load.

---

# Sesi 1 — Model, Migration, Factory & Seeder

Matlamat: cipta model `Department`, table `departments`, data contoh, dan faham operasi asas **CRUD** (Create, Read, Update, Delete) dengan Eloquent.

## Apa itu Eloquent?
Eloquent ialah **ORM** (Object-Relational Mapper). Satu **class model** mewakili satu **table**, dan satu **object** mewakili satu **row**.

| Database | Eloquent |
| --- | --- |
| Table `departments` | Class `App\Models\Department` |
| Satu row | `$department = Department::find(1)` |
| Column `name` | `$department->name` |
| `INSERT` / `UPDATE` / `DELETE` | `create()` / `save()` / `delete()` |

Konvensyen nama: model **singular PascalCase** (`Department`), table **plural snake_case** (`departments`). Laravel teka nama table sendiri.

## Cipta model sekaligus dengan migration, factory dan seeder
```bash
docker compose exec app php artisan make:model Department -mfs
```
| Flag | Cipta |
| --- | --- |
| `-m` | Migration `database/migrations/xxxx_create_departments_table.php` |
| `-f` | Factory `database/factories/DepartmentFactory.php` |
| `-s` | Seeder `database/seeders/DepartmentSeeder.php` |

## Migration
`database/migrations/xxxx_create_departments_table.php`
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
```
- `id()`: primary key `id` (auto increment).
- `timestamps()`: column `created_at` dan `updated_at`. Eloquent isi kedua-duanya secara automatik.
- `down()`: cara undo migration (`migrate:rollback`).

```bash
docker compose exec app php artisan migrate
docker compose exec app php artisan migrate:status
```

## Model
`app/Models/Department.php`
```php
<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'is_active'])]
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
```

### Mass assignment & `#[Fillable]`
`Department::create($request->all())` sangat bahaya jika semua column boleh diisi. Contohnya, user boleh hantar `is_admin=1` dalam form.
`#[Fillable([...])]` ialah **senarai column yang dibenarkan** untuk `create()`/`fill()`/`update()`. Column lain diabaikan.
(Cara lama: `protected $fillable = [...]`. Laravel 13 guna attribute seperti dalam model `User`.)

## Factory
`database/factories/DepartmentFactory.php`
```php
<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'code' => fake()->unique()->lexify('???'),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the department is not active.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
```
Factory cipta data **palsu** untuk test dan development: `Department::factory()->count(3)->create()`, `Department::factory()->inactive()->create()`.

## Seeder
Seeder cipta data **tetap** yang app perlukan.

`database/seeders/DepartmentSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Seed the departments.
     */
    public function run(): void
    {
        $departments = [
            ['code' => 'IT', 'name' => 'Information Technology'],
            ['code' => 'HR', 'name' => 'Human Resources'],
            ['code' => 'FIN', 'name' => 'Finance'],
            ['code' => 'MKT', 'name' => 'Marketing'],
            ['code' => 'OPS', 'name' => 'Operations', 'is_active' => false],
        ];

        foreach ($departments as $department) {
            Department::updateOrCreate(
                ['code' => $department['code']],
                $department,
            );
        }
    }
}
```
`updateOrCreate(cari, data)` cari row dengan `code` itu. Jika ada, ia **update**. Jika tiada, ia **create**. Jadi seeder boleh dijalankan berkali-kali tanpa duplicate.

```bash
docker compose exec app php artisan db:seed --class=DepartmentSeeder
```

## CRUD dalam tinker
```php
// CREATE
> $d = Department::create(['name' => 'Legal', 'code' => 'LEG']);
> $d->id;                       // id baru
> $d->is_active;                // null! default database belum dibaca semula
> $d->fresh()->is_active;       // true  (fresh() ambil semula dari database)

> $d = new Department;          // cara lain: object dahulu, save kemudian
> $d->name = 'Research';
> $d->code = 'RND';
> $d->save();

// READ
> Department::all();                       // semua row (Collection)
> Department::find(1);                     // ikut id, null jika tiada
> Department::findOrFail(999);             // throw ModelNotFoundException (jadi 404 dalam controller)
> Department::where('code', 'IT')->first();
> Department::firstWhere('code', 'HR');

// UPDATE
> $d = Department::firstWhere('code', 'LEG');
> $d->name = 'Legal & Compliance';
> $d->isDirty();                // true, ada perubahan belum disimpan
> $d->save();
> $d->update(['is_active' => false]);      // fill + save sekaligus

// DELETE
> $d->delete();
> Department::destroy([6, 7]);             // ikut id
> Department::where('code', 'RND')->delete();

// Mass assignment
> Department::create(['name' => 'X', 'code' => 'X1', 'id' => 999]);   // 'id' diabaikan (tiada dalam Fillable)
```

> **`firstOrCreate` vs `updateOrCreate`:** `firstOrCreate(cari, data)` hanya cipta jika tiada, dan **tidak** update row sedia ada.

## Cuba sendiri
1. `docker compose exec app php artisan db:table departments`: lihat struktur table.
2. Dalam tinker: `Department::count()` → 5. Jalankan seeder sekali lagi → masih 5 (`updateOrCreate`).
3. `Department::factory()->inactive()->make()`: `make()` cipta object **tanpa** simpan ke database. Bandingkan dengan `create()`.
4. `docker compose exec app php artisan model:show Department`: column, cast, dan relationship model.

---

# Sesi 2 — Query & Scope

Matlamat: tulis query Eloquent, dan pindahkan logic **search** dalam Users page ke dalam **local scope** supaya boleh diguna semula.

## Query builder
Setiap method seperti `where()` **tidak** jalankan query. Query hanya dijalankan bila anda panggil `get()`, `first()`, `count()`, `paginate()`, dan lain-lain.

```php
> User::where('email', 'like', '%example.com')->get();
> User::where('name', 'like', 'A%')->orWhere('name', 'like', 'B%')->get();
> User::whereIn('id', [1, 2, 3])->get();
> User::whereNull('email_verified_at')->count();
> User::whereDate('created_at', today())->get();
> User::whereBetween('id', [5, 10])->pluck('name');

> User::orderBy('name')->limit(5)->get();
> User::latest()->first();                     // orderBy created_at desc
> User::oldest('name')->first();

> User::pluck('email');                         // Collection email sahaja
> User::pluck('name', 'id');                    // [id => name]
> User::count();
> User::max('id');
> User::exists();

> User::where('name', 'like', 'A%')->toSql();  // lihat SQL tanpa jalankan
> User::where('name', 'like', 'A%')->toRawSql();
```

### `get()` vs `first()` vs `paginate()`
| Method | Pulang | Guna |
| --- | --- | --- |
| `get()` | `Collection` (banyak model) | Senarai kecil |
| `first()` | Satu model atau `null` | Cari satu |
| `paginate(10)` | `LengthAwarePaginator` | Senarai dalam page (`$users->links()`) |
| `each()` / `chunk(100, fn)` | — | Proses banyak row tanpa penuhkan memory |

### Collection
`get()` pulang **Collection**, iaitu array dengan banyak method berguna. Method ini berjalan dalam **PHP**, bukan SQL:
```php
> $users = User::all();
> $users->count();
> $users->pluck('name')->sort()->values();
> $users->filter(fn ($u) => str_ends_with($u->email, '.org'));
> $users->groupBy(fn ($u) => substr($u->name, 0, 1))->map->count();
```
> Jika boleh, tapis dalam **query** (`where`), bukan dalam Collection (`filter`). Database lebih cepat, dan tidak perlu load semua row ke dalam memory.

## Local scope
Users page (`UserController@index`) dan bulk email (`UserEmailController@recipients`) kedua-duanya ada code search yang **sama**:
```php
->when($search, fn ($query) => $query->where(function ($query) use ($search) {
    $query->where('name', 'like', "%{$search}%")
        ->orWhere('email', 'like', "%{$search}%");
}))
```
Kita pindahkan ke dalam model sebagai **scope**.

`app/Models/User.php` (tambah `use` dan method)
```php
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

// ...

/**
 * Search by name or email: User::search('ali')->get()
 *
 * @param  Builder<User>  $query
 */
#[Scope]
protected function search(Builder $query, ?string $term): void
{
    $query->when($term, fn (Builder $query) => $query->where(function (Builder $query) use ($term) {
        $query->where('name', 'like', "%{$term}%")
            ->orWhere('email', 'like', "%{$term}%");
    }));
}
```
- `#[Scope]` jadikan method `protected` ini boleh dipanggil sebagai `User::search(...)` atau `->search(...)`.
- `when($term, ...)` hanya tambah `where` jika `$term` tidak kosong.
- `where(function ...)` bungkus `OR` dalam kurungan: `WHERE (name LIKE ? OR email LIKE ?)`. Tanpa kurungan, `OR` akan pecahkan syarat lain seperti `whereKeyNot`.

Guna dalam controller.

`app/Http/Controllers/UserController.php`
```php
$users = User::query()
    ->with('roles')
    ->search($search)
    ->latest()
    ->paginate(10)
    ->withQueryString();
```

`app/Http/Controllers/UserEmailController.php`
```php
private function recipients(Request $request, string $search): Builder
{
    return User::query()
        ->whereKeyNot($request->user()->getKey())
        ->search($search);
}
```

Scope untuk Department juga.

`app/Models/Department.php`
```php
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

// ...

/**
 * Only active departments: Department::active()->get()
 *
 * @param  Builder<Department>  $query
 */
#[Scope]
protected function active(Builder $query): void
{
    $query->where('is_active', true);
}
```

```php
> Department::active()->pluck('code');            // OPS tiada
> User::search('example.com')->count();
> User::search('admin')->whereKeyNot(1)->toRawSql();
```

## Cuba sendiri
1. Users page: search masih berfungsi seperti dulu (code berubah, hasil sama).
2. **Email all** dengan search → bilangan user dalam form sama dengan dalam table.
3. Dalam tinker, bandingkan `User::search('a')->toSql()` dengan dan tanpa `where(function ...)`. Apa beza kurungan dalam SQL?

---

# Sesi 3 — Relationship One-to-Many & Eager Loading

Matlamat: setiap user ada satu **department**. Tunjuk department dalam Users page, cipta page **Departments** dengan bilangan user, dan elak masalah **N+1 query**.

## Migration: tambah `department_id` pada users
```bash
docker compose exec app php artisan make:migration add_department_id_to_users_table
```

`database/migrations/xxxx_add_department_id_to_users_table.php`
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->foreignId('department_id')
            ->nullable()
            ->after('email')
            ->constrained()
            ->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropConstrainedForeignId('department_id');
    });
}
```
| Bahagian | Maksud |
| --- | --- |
| `foreignId('department_id')` | Column `BIGINT UNSIGNED`. Nama `{model}_id` ialah konvensyen Eloquent. |
| `nullable()` | User boleh tiada department. |
| `constrained()` | Foreign key ke `departments.id` (Laravel teka dari nama column). |
| `nullOnDelete()` | Bila department dipadam, `department_id` user jadi `NULL` (user tidak dipadam). |

```bash
docker compose exec app php artisan migrate
```

## Relationship dalam model
`app/Models/Department.php`
```php
use Illuminate\Database\Eloquent\Relations\HasMany;

// ...

/**
 * Users in this department.
 *
 * @return HasMany<User, $this>
 */
public function users(): HasMany
{
    return $this->hasMany(User::class);
}
```

`app/Models/User.php`
```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// ...

/**
 * The department this user belongs to.
 *
 * @return BelongsTo<Department, $this>
 */
public function department(): BelongsTo
{
    return $this->belongsTo(Department::class);
}
```
Peraturan mudah: model yang ada column **foreign key** (`users.department_id`) guna `belongsTo`. Model di sebelah lagi guna `hasMany`.

## Method vs property
```php
> $user = User::find(1);
> $user->department;              // property: jalankan query dan pulang model Department (atau null)
> $user->department();            // method: pulang relationship (query builder), boleh tambah syarat

> $it = Department::firstWhere('code', 'IT');
> $it->users;                     // Collection user
> $it->users()->where('name', 'like', 'A%')->get();
> $it->users()->count();          // COUNT dalam SQL, tanpa load semua user
```

## Simpan relationship
```php
> $user = User::find(5);
> $user->department()->associate($it);   // set department_id
> $user->save();
> $user->department()->dissociate();     // department_id = null
> $user->save();

> $it->users()->save(User::find(6));     // dari sebelah hasMany
> $it->users()->create([...]);           // cipta user baru terus dalam department
```
> `department_id` sengaja **tidak** dimasukkan dalam `#[Fillable]` model `User`, supaya form profile tidak boleh tukar department. Guna `associate()`.

## Seeder: beri setiap user satu department
`database/seeders/DatabaseSeeder.php`
```php
use App\Models\Department;

// ...

public function run(): void
{
    $this->call([
        RolePermissionSeeder::class,
        DepartmentSeeder::class,
    ]);

    // ... User::factory()->create([...]) seperti sebelum ini ...

    // Users without any role
    User::factory(25)->create();

    // Put every user in a random department
    $departments = Department::all();

    User::query()->each(function (User $user) use ($departments) {
        $user->department()->associate($departments->random());
        $user->save();
    });
}
```
```bash
docker compose exec app php artisan migrate:fresh --seed
```
> ⚠️ `migrate:fresh` **padam semua table** dan cipta semula. Guna hanya dalam development.

## Masalah N+1 query
Tambah column Department dalam Users page.

`resources/views/users/index.blade.php`: tambah column header selepas Email:
```blade
<flux:table.column>{{ __('Department') }}</flux:table.column>
```
dan cell selepas `{{ $user->email }}`:
```blade
<flux:table.cell>{{ $user->department?->code ?? '-' }}</flux:table.cell>
```
Tukar juga `colspan` dalam row "No users found." supaya sama dengan bilangan column.

Jika controller **tidak** load department, setiap row jalankan satu query:
```
select * from users limit 10            ← 1 query
select * from departments where id = 3  ← row 1
select * from departments where id = 1  ← row 2
... 10 kali                             ← N query
```
Ini dipanggil **N+1**. Dengan 10 row ia tidak terasa, tetapi dengan 100 row dan 3 relationship, satu page boleh ada 300 query.

### Penyelesaian: eager loading dengan `with()`
`app/Http/Controllers/UserController.php`
```php
$users = User::query()
    ->with(['roles', 'department'])
    ->search($search)
    ->latest()
    ->paginate(10)
    ->withQueryString();
```
Sekarang hanya 2 query: `select * from users ...` dan `select * from departments where id in (3, 1, ...)`.

### Tangkap N+1 secara automatik
`app/Providers/AppServiceProvider.php`
```php
use Illuminate\Database\Eloquent\Model;

// ... dalam configureDefaults()

// Throw an error on N+1 queries (lazy loading) while developing
Model::preventLazyLoading(! app()->isProduction());
```
Sekarang jika anda lupa `with('department')`, page terus keluar error `LazyLoadingViolationException`. Ini **hanya** berlaku dalam development, dan **tidak** dalam production.

```php
// Cuba dalam tinker
> User::all()->each(fn ($u) => $u->department->name);              // error: lazy loading
> User::with('department')->get()->each(fn ($u) => $u->department->name);   // OK
> User::find(1)->department;                                        // OK: satu model sahaja tidak dikira N+1
```

## Page Departments dengan `withCount`
```bash
docker compose exec app php artisan make:controller DepartmentController
```

`app/Http/Controllers/DepartmentController.php`
```php
<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    /**
     * List departments with how many users are in each.
     */
    public function index(): View
    {
        $departments = Department::query()
            ->withCount('users')
            ->orderBy('name')
            ->get();

        return view('departments.index', [
            'departments' => $departments,
        ]);
    }
}
```
`withCount('users')` tambah column `users_count` guna **satu** subquery `COUNT`. Ia jauh lebih baik daripada `$department->users->count()` (load semua user) dalam loop.

`resources/views/departments/index.blade.php`
```blade
<x-layouts::app :title="__('Departments')">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Departments') }}</flux:heading>
        <flux:subheading>{{ __('All departments and how many users are in each') }}</flux:subheading>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Department') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Users') }}</flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @forelse ($departments as $department)
                <flux:table.row>
                    <flux:table.cell>{{ $department->code }} - {{ $department->name }}</flux:table.cell>

                    <flux:table.cell>
                        @if ($department->is_active)
                            <flux:badge size="sm" color="green" inset="top bottom">{{ __('Active') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('Inactive') }}</flux:badge>
                        @endif
                    </flux:table.cell>

                    <flux:table.cell align="end">{{ $department->users_count }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="3" class="text-center">{{ __('No departments found.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>
</x-layouts::app>
```

`routes/web.php` (dalam group `auth`)
```php
use App\Http\Controllers\DepartmentController;

// ...

Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
```

`resources/views/layouts/app/sidebar.blade.php` (sebelum link Users)
```blade
<flux:sidebar.item icon="building-office" :href="route('departments.index')" :current="request()->routeIs('departments.*')" wire:navigate>
    {{ __('Departments') }}
</flux:sidebar.item>
```

## Query ikut relationship
```php
> Department::has('users')->get();                         // department yang ada user
> Department::doesntHave('users')->get();                  // department kosong
> Department::has('users', '>=', 5)->pluck('code');
> User::whereHas('department', fn ($q) => $q->where('code', 'IT'))->count();
> User::whereRelation('department', 'code', 'IT')->count();   // versi ringkas
> User::whereBelongsTo($it)->count();                      // sama: where department_id = $it->id
> User::with('department:id,code')->first();               // load column tertentu sahaja
```

## Cuba sendiri
1. Users page ada column **Department**. Buang `'department'` dari `with()` dan refresh → error `LazyLoadingViolationException`. Letak semula.
2. Page **Departments** tunjuk bilangan user setiap department. Jumlah semua = bilangan user.
3. Dalam tinker, padam satu department yang ada user → user tersebut kini `department_id = null` (`nullOnDelete`). Jalankan `db:seed --class=DepartmentSeeder` untuk cipta semula.

---

# Sesi 4 — Relationship Many-to-Many (pivot)

Matlamat: model **Project**. Satu user boleh masuk banyak project, dan satu project ada banyak user. Setiap ahli ada **role** dalam project (`lead` / `member`), dan role ini disimpan dalam **pivot table**.

## Cipta model dan pivot table
```bash
docker compose exec app php artisan make:model Project -mfs
docker compose exec app php artisan make:migration create_project_user_table
```
Nama pivot table ialah **dua model singular, ikut abjad, dipisahkan `_`**: `project` + `user` = `project_user`.

`database/migrations/xxxx_create_projects_table.php`
```php
Schema::create('projects', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('status')->default('planning');
    $table->date('deadline')->nullable();
    $table->timestamps();
    $table->softDeletes();          // column deleted_at (diterangkan dalam Sesi 5)
});
```

`database/migrations/xxxx_create_project_user_table.php`
```php
Schema::create('project_user', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('role')->default('member');
    $table->timestamps();

    $table->unique(['project_id', 'user_id']);
});
```
- `cascadeOnDelete()`: bila user/project dipadam **betul-betul** dari database, row pivot juga dipadam.
- `unique([...])`: user yang sama tidak boleh masuk project yang sama dua kali.

```bash
docker compose exec app php artisan migrate
```

## Model
`app/Models/Project.php` (versi awal, akan dilengkapkan dalam Sesi 5)
```php
<?php

namespace App\Models;

use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'status', 'deadline'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Users working on this project (pivot table: project_user).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }
}
```

`app/Models/User.php`
```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// ...

/**
 * Projects this user works on (pivot table: project_user).
 *
 * @return BelongsToMany<Project, $this>
 */
public function projects(): BelongsToMany
{
    return $this->belongsToMany(Project::class)
        ->withPivot('role')
        ->withTimestamps();
}
```
- Kedua-dua sebelah guna `belongsToMany`.
- `withPivot('role')`: baca column tambahan pivot sebagai `$project->pivot->role`.
- `withTimestamps()`: isi `created_at`/`updated_at` dalam pivot.

## Factory & Seeder
`database/factories/ProjectFactory.php` (versi awal, guna string. Dalam Sesi 5 kita tukar ke enum)
```php
public function definition(): array
{
    return [
        'name' => ucfirst(fake()->words(2, true)),
        'status' => fake()->randomElement(['planning', 'active', 'completed']),
        'deadline' => fake()->dateTimeBetween('-1 month', '+3 months'),
    ];
}
```

`database/seeders/ProjectSeeder.php`
```php
<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Seed projects and put users in them.
     */
    public function run(): void
    {
        $users = User::all();

        Project::factory(6)->create()->each(function (Project $project) use ($users) {
            $team = $users->random(4);

            // First user leads the project, the rest are members
            $project->users()->attach($team->first(), ['role' => 'lead']);
            $project->users()->attach($team->skip(1), ['role' => 'member']);
        });
    }
}
```

`database/seeders/DatabaseSeeder.php`: panggil di **hujung** `run()` (selepas user dicipta):
```php
$this->call(ProjectSeeder::class);
```
```bash
docker compose exec app php artisan migrate:fresh --seed
```

## Attach, detach, sync
```php
> $project = Project::first();
> $user = User::find(5);

> $project->users()->attach($user, ['role' => 'member']);   // tambah row pivot
> $project->users()->attach([6, 7]);                        // guna id, role default 'member'
> $project->users()->detach($user);                         // buang
> $project->users()->detach();                              // buang semua ahli

> $project->users()->sync([5, 6, 7]);                       // pivot jadi TEPAT [5,6,7]: tambah yang tiada, buang yang lain
> $project->users()->sync([5 => ['role' => 'lead'], 6, 7]); // sync dengan data pivot
> $project->users()->syncWithoutDetaching([8]);             // tambah tanpa buang yang lain
> $project->users()->toggle([5, 9]);                        // ada → buang, tiada → tambah
> $project->users()->updateExistingPivot(6, ['role' => 'lead']);
```
| Method | Bila guna |
| --- | --- |
| `attach` | Tambah ahli baru |
| `detach` | Buang ahli |
| `sync` | Form "pilih ahli" (checkbox): simpan pilihan terkini sahaja |
| `syncWithoutDetaching` | Tambah tanpa sentuh ahli sedia ada |
| `updateExistingPivot` | Tukar data pivot (contoh role) |

## Baca data pivot
```php
> foreach ($project->users as $u) { echo $u->name.' - '.$u->pivot->role.PHP_EOL; }
> $project->users()->wherePivot('role', 'lead')->first();
> User::find(5)->projects->pluck('name');
> Project::withCount('users')->get()->pluck('users_count', 'name');
> Project::whereHas('users', fn ($q) => $q->where('users.id', 5))->get();
```

## Tunjuk bilangan project dalam Users page
`app/Http/Controllers/UserController.php`
```php
$users = User::query()
    ->with(['roles', 'department'])
    ->withCount('projects')
    ->search($search)
    ->latest()
    ->paginate(10)
    ->withQueryString();
```

`resources/views/users/index.blade.php`: tambah header selepas Department:
```blade
<flux:table.column>{{ __('Projects') }}</flux:table.column>
```
dan cell:
```blade
<flux:table.cell>{{ $user->projects_count }}</flux:table.cell>
```
Kemas kini `colspan` row "No users found." kepada `8`.

## Cuba sendiri
1. Users page ada column **Projects** dengan bilangan.
2. Dalam tinker, `attach` user yang sama dua kali → error **Duplicate entry** (index `unique`). Guna `syncWithoutDetaching` untuk elak.
3. Cari semua project di mana user 1 ialah `lead`:
   `User::find(1)->projects()->wherePivot('role', 'lead')->get()`.

---

# Sesi 5 — Casts, Enum, Accessor/Mutator & Soft Delete

Matlamat: buat data model lebih "pintar":
- `status` jadi **enum** PHP
- `deadline` jadi object **tarikh**
- `code` department sentiasa **huruf besar**
- `label` siap untuk dipaparkan
- project yang dipadam boleh **dikembalikan**

## Casts
Cast tukar nilai column bila **dibaca** dari dan **disimpan** ke database.

| Cast | Database | PHP |
| --- | --- | --- |
| `'boolean'` | `1` / `0` | `true` / `false` |
| `'date'` / `'datetime'` | `'2026-12-31'` | object `CarbonImmutable` (boleh `->format()`, `->isPast()`) |
| `'integer'`, `'decimal:2'` | string | nombor |
| `'array'` / `'json'` | teks JSON | array |
| `'hashed'` | hash | password di-hash automatik (lihat model `User`) |
| `ProjectStatus::class` | `'active'` | `ProjectStatus::Active` |

## Enum
```bash
docker compose exec app php artisan make:enum Enums/ProjectStatus --string
```

`app/Enums/ProjectStatus.php`
```php
<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planning = 'planning';
    case Active = 'active';
    case Completed = 'completed';

    /**
     * Text to show in the UI.
     */
    public function label(): string
    {
        return match ($this) {
            self::Planning => __('Planning'),
            self::Active => __('Active'),
            self::Completed => __('Completed'),
        };
    }
}
```

## Model Project (versi penuh)
`app/Models/Project.php`
```php
<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'status', 'deadline'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'deadline' => 'date',
        ];
    }

    /**
     * Users working on this project (pivot table: project_user).
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Not completed and the deadline has passed: Project::overdue()->get()
     *
     * @param  Builder<Project>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->where('status', '!=', ProjectStatus::Completed)
            ->whereDate('deadline', '<', today());
    }
}
```

Factory guna enum.

`database/factories/ProjectFactory.php`
```php
use App\Enums\ProjectStatus;

// ...

public function definition(): array
{
    return [
        'name' => ucfirst(fake()->words(2, true)),
        'status' => fake()->randomElement(ProjectStatus::cases()),
        'deadline' => fake()->dateTimeBetween('-1 month', '+3 months'),
    ];
}

/**
 * Indicate that the project is completed.
 */
public function completed(): static
{
    return $this->state(fn (array $attributes) => [
        'status' => ProjectStatus::Completed,
    ]);
}
```

```php
> $p = Project::first();
> $p->status;                          // App\Enums\ProjectStatus::Active (bukan string)
> $p->status->label();                 // "Active"
> $p->status === ProjectStatus::Active;
> $p->deadline->format('d M Y');       // "31 Dec 2026"
> $p->deadline->isPast();
> $p->update(['status' => ProjectStatus::Completed]);   // simpan sebagai 'completed'
> $p->update(['status' => 'unknown']);                  // error: ValueError (nilai tidak sah ditolak)
> Project::overdue()->pluck('name');
> Project::where('status', ProjectStatus::Active)->count();
```

## Accessor & Mutator
- **Accessor** (`get`): ubah nilai bila **dibaca**, atau cipta attribute "maya" yang tiada dalam table.
- **Mutator** (`set`): ubah nilai sebelum **disimpan**.

Kedua-duanya ditulis sebagai method `protected` yang pulang `Attribute`. Nama method ialah nama attribute dalam camelCase.

`app/Models/Department.php`
```php
use Illuminate\Database\Eloquent\Casts\Attribute;

// ...

/**
 * Always save the code in uppercase ("it" becomes "IT").
 *
 * @return Attribute<string, string>
 */
protected function code(): Attribute
{
    return Attribute::make(
        set: fn (string $value) => strtoupper($value),
    );
}

/**
 * "IT - Information Technology", read as $department->label
 *
 * @return Attribute<string, never>
 */
protected function label(): Attribute
{
    return Attribute::make(
        get: fn () => "{$this->code} - {$this->name}",
    );
}
```

```php
> $d = Department::create(['name' => 'Legal', 'code' => 'leg']);
> $d->code;        // "LEG"
> $d->label;       // "LEG - Legal"
> $d->delete();
```

Guna accessor dalam page Departments.

`resources/views/departments/index.blade.php`
```blade
<flux:table.cell>{{ $department->label }}</flux:table.cell>
```
Format label kini di **satu tempat** (model). Jika mahu tukar format, ubah model sahaja.

## Soft Delete
`use SoftDeletes` + column `deleted_at` (`$table->softDeletes()` dalam migration Sesi 4).
`delete()` **tidak** padam row. Ia isi `deleted_at`, dan semua query biasa abaikan row itu secara automatik.

```php
> $p = Project::first();
> $p->delete();                         // UPDATE projects SET deleted_at = now()
> Project::count();                     // berkurang 1
> Project::withTrashed()->count();      // termasuk yang dipadam
> Project::onlyTrashed()->get();        // yang dipadam sahaja
> $p->trashed();                        // true
> $p->restore();                        // deleted_at = null
> $p->forceDelete();                    // padam betul-betul (pivot ikut dipadam, cascadeOnDelete)
```
> Row pivot `project_user` **tidak** dipadam semasa soft delete. Bila project di-`restore()`, semua ahli masih ada.

## Cuba sendiri
1. Page **Departments** tunjuk "IT - Information Technology" melalui `$department->label`.
2. Dalam tinker, `Department::create(['name' => 'Test', 'code' => 'tst'])->code` → `TST`.
3. `Project::overdue()->get()`: semua project ada `deadline` lepas dan status bukan Completed.
4. Soft delete satu project → `User::find(id)->projects` tidak lagi tunjuk project itu, tetapi `restore()` kembalikan semuanya.

---

## Test
Semua contoh di atas ada test dalam `tests/Feature/EloquentTest.php`. Contoh:
```php
test('department code is saved in uppercase and has a label', function () {
    $department = Department::create(['name' => 'Information Technology', 'code' => 'it']);

    expect($department->code)->toBe('IT')
        ->and($department->label)->toBe('IT - Information Technology')
        ->and($department->is_active)->toBeNull()          // database default is not loaded yet
        ->and($department->fresh()->is_active)->toBeTrue();
});

test('lazy loading inside a loop throws an error', function () {
    User::factory(2)->for(Department::factory())->create();

    User::all()->each(fn (User $user) => $user->department->name);
})->throws(LazyLoadingViolationException::class);
```
`User::factory()->for($department)` cipta user dalam department tersebut (isi `department_id` secara automatik).

```bash
docker compose exec app php artisan test --filter=Eloquent
```

---

## Ringkasan
| Topik | Guna | Ingat |
| --- | --- | --- |
| Model & Migration | `make:model X -mfs`, `migrate` | Model singular, table plural |
| Mass assignment | `#[Fillable([...])]` | Hanya column yang disenaraikan boleh `create()`/`update()` |
| Query | `where`, `orderBy`, `pluck`, `paginate` | Query hanya jalan bila `get()`/`first()`/`count()` |
| Scope | `#[Scope] protected function search(...)` | Guna semula logic query: `User::search($term)` |
| One-to-many | `hasMany` / `belongsTo` | Foreign key ada pada sebelah `belongsTo` |
| Many-to-many | `belongsToMany` + pivot | `attach`, `detach`, `sync`, `withPivot` |
| Eager loading | `with()`, `withCount()` | Elak N+1. `preventLazyLoading()` tangkap kesilapan |
| Casts & Enum | `casts()`, `ProjectStatus::class` | Nilai jadi jenis PHP yang betul |
| Accessor/Mutator | `Attribute::make(get:, set:)` | Format data di satu tempat |
| Soft delete | `SoftDeletes`, `softDeletes()` | `withTrashed()`, `restore()`, `forceDelete()` |

## Command berguna
```bash
docker compose exec app php artisan make:model Product -mfs       # model + migration + factory + seeder
docker compose exec app php artisan make:migration add_x_to_y_table
docker compose exec app php artisan migrate                       # jalankan migration baru
docker compose exec app php artisan migrate:status
docker compose exec app php artisan migrate:rollback              # undo batch terakhir
docker compose exec app php artisan migrate:fresh --seed          # ⚠️ padam semua table, migrate & seed semula
docker compose exec app php artisan db:seed --class=DepartmentSeeder
docker compose exec app php artisan model:show User               # column, cast, relationship
docker compose exec app php artisan db:table users                # struktur table
docker compose exec app php artisan db:show                       # ringkasan database
docker compose exec app php artisan tinker                        # cuba query secara interaktif
```
