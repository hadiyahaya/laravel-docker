<?php

use App\Jobs\SendUserMessage;
use App\Mail\UserMessage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

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

test('admin can queue emails to all users matching the search, except themselves', function () {
    Queue::fake();

    $admin = User::factory()->create(['name' => 'Ali Admin'])->assignRole('admin');
    User::factory()->create(['name' => 'Ali Baba']);
    User::factory()->create(['name' => 'Ali Khan']);
    User::factory()->create(['name' => 'Siti']);

    $this->actingAs($admin)
        ->post(route('users.email-bulk.store'), ['search' => 'Ali', 'subject' => 'Hi', 'body' => 'Hello all'])
        ->assertRedirect(route('users.index', ['search' => 'Ali']));

    Queue::assertPushed(SendUserMessage::class, 2);
});

test('staff cannot email users', function () {
    $staff = User::factory()->create()->assignRole('staff');
    $user = User::factory()->create();

    $this->actingAs($staff)->get(route('users.email.create', $user))->assertForbidden();
    $this->actingAs($staff)->get(route('users.email-bulk.create'))->assertForbidden();
});

test('subject and message are required', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('users.email.store', $user), [])
        ->assertSessionHasErrors(['subject', 'body']);
});

test('admin sees the email buttons and forms', function () {
    $admin = User::factory()->create()->assignRole('admin');
    $user = User::factory()->create();

    $this->actingAs($admin)->get(route('users.index'))
        ->assertOk()
        ->assertSee(route('users.email.create', $user))
        ->assertSee(route('users.email-bulk.create'));

    $this->actingAs($admin)->get(route('users.email.create', $user))->assertOk()->assertSee($user->email);
    $this->actingAs($admin)->get(route('users.email-bulk.create'))->assertOk()->assertSee('To 1 users');
});
