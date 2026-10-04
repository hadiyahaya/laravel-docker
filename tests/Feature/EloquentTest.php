<?php

use App\Enums\ProjectStatus;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the database seeder creates departments, users and projects', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Department::count())->toBe(5)
        ->and(User::whereNull('department_id')->count())->toBe(0)
        ->and(Project::count())->toBe(6)
        ->and(Project::first()->users()->wherePivot('role', 'lead')->count())->toBe(1);
});

test('department code is saved in uppercase and has a label', function () {
    $department = Department::create(['name' => 'Information Technology', 'code' => 'it']);

    expect($department->code)->toBe('IT')
        ->and($department->label)->toBe('IT - Information Technology')
        ->and($department->is_active)->toBeNull()          // database default is not loaded yet
        ->and($department->fresh()->is_active)->toBeTrue();
});

test('active scope only returns active departments', function () {
    Department::factory()->count(2)->create();
    Department::factory()->inactive()->create();

    expect(Department::active()->count())->toBe(2);
});

test('a user belongs to a department and a department has many users', function () {
    $department = Department::factory()->create();
    $user = User::factory()->for($department)->create();

    expect($user->department->is($department))->toBeTrue()
        ->and($department->users)->toHaveCount(1);
});

test('users can be attached to projects with a pivot role', function () {
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $project->users()->attach($user, ['role' => 'lead']);

    expect($user->projects->first()->pivot->role)->toBe('lead');

    $project->users()->sync([]);

    expect($project->users()->count())->toBe(0);
});

test('project status is cast to an enum and deadline to a date', function () {
    $project = Project::factory()->create(['status' => 'active', 'deadline' => '2026-12-31']);

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($project->deadline->format('d M Y'))->toBe('31 Dec 2026');
});

test('overdue scope ignores completed projects', function () {
    Project::factory()->create(['status' => ProjectStatus::Active, 'deadline' => now()->subDay()]);
    Project::factory()->completed()->create(['deadline' => now()->subDay()]);
    Project::factory()->create(['status' => ProjectStatus::Active, 'deadline' => now()->addDay()]);

    expect(Project::overdue()->count())->toBe(1);
});

test('projects are soft deleted and can be restored', function () {
    $project = Project::factory()->create();

    $project->delete();

    expect(Project::count())->toBe(0)
        ->and(Project::withTrashed()->count())->toBe(1);

    $project->restore();

    expect(Project::count())->toBe(1);
});

test('lazy loading inside a loop throws an error', function () {
    User::factory(2)->for(Department::factory())->create();

    User::all()->each(fn (User $user) => $user->department->name);
})->throws(LazyLoadingViolationException::class);

test('departments page shows user counts', function () {
    $department = Department::factory()->create(['name' => 'Finance', 'code' => 'FIN']);
    User::factory(3)->for($department)->create();

    $this->actingAs(User::factory()->create())
        ->get(route('departments.index'))
        ->assertOk()
        ->assertSee('FIN - Finance');
});
