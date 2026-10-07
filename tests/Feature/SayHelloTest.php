<?php

use App\Jobs\SayHello;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->staff = User::factory()->create(['name' => 'Staff User'])->assignRole('staff');
});

test('users page shows the say hello button', function () {
    $this->actingAs($this->staff)
        ->get(route('users.index'))
        ->assertOk()
        ->assertSee(route('users.say-hello'));
});

test('queue mode pushes one job with the current user name', function () {
    Queue::fake();

    $this->actingAs($this->staff)
        ->post(route('users.say-hello'), ['mode' => 'queue'])
        ->assertRedirect(route('users.index'));

    Queue::assertPushed(SayHello::class, fn (SayHello $job) => $job->name === 'Staff User');
});

test('many mode pushes one job per user in the table, up to 4', function () {
    Queue::fake();

    User::factory(5)->create();

    $this->actingAs($this->staff)->post(route('users.say-hello'), ['mode' => 'many']);

    Queue::assertPushed(SayHello::class, 4);
});

test('delay mode pushes a delayed job', function () {
    Queue::fake();

    $this->actingAs($this->staff)->post(route('users.say-hello'), ['mode' => 'delay']);

    Queue::assertPushed(SayHello::class, fn (SayHello $job) => $job->delay !== null);
});

test('users without a role cannot run the job', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('users.say-hello'), ['mode' => 'queue'])
        ->assertForbidden();
});
