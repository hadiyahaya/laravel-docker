<?php

use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserEmailController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');

    Route::get('users', [UserController::class, 'index'])
        ->can('viewAny', User::class)
        ->name('users.index');

    // Bulk email - keep these above the users/{user} routes
    Route::get('users/email', [UserEmailController::class, 'createBulk'])
        ->can('emailAny', User::class)
        ->name('users.email-bulk.create');

    Route::post('users/email', [UserEmailController::class, 'storeBulk'])
        ->can('emailAny', User::class)
        ->name('users.email-bulk.store');

    Route::get('users/{user}/edit', [UserController::class, 'edit'])
        ->can('update', 'user')
        ->name('users.edit');

    Route::put('users/{user}', [UserController::class, 'update'])
        ->can('update', 'user')
        ->name('users.update');

    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->can('delete', 'user')
        ->name('users.destroy');

    Route::get('users/{user}/email', [UserEmailController::class, 'create'])
        ->can('email', 'user')
        ->name('users.email.create');

    Route::post('users/{user}/email', [UserEmailController::class, 'store'])
        ->can('email', 'user')
        ->name('users.email.store');
});

require __DIR__.'/settings.php';
