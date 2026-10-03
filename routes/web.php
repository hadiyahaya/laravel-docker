<?php

use App\Http\Controllers\UserController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::get('users', [UserController::class, 'index'])
        ->can('viewAny', User::class)
        ->name('users.index');

    Route::get('users/{user}/edit', [UserController::class, 'edit'])
        ->can('update', 'user')
        ->name('users.edit');

    Route::put('users/{user}', [UserController::class, 'update'])
        ->can('update', 'user')
        ->name('users.update');

    Route::delete('users/{user}', [UserController::class, 'destroy'])
        ->can('delete', 'user')
        ->name('users.destroy');
});

require __DIR__.'/settings.php';
