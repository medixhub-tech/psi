<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/painel');
Route::middleware('guest')->group(function () {
    Route::view('/entrar', 'auth.login')->name('login');
    Route::post('/entrar', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::view('/recuperar-senha', 'auth.forgot')->name('password.request');
    Route::post('/recuperar-senha', [AuthController::class, 'forgot'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/redefinir-senha/{token}', fn (string $token) => view('auth.reset', ['token' => $token]))->name('password.reset');
    Route::post('/redefinir-senha', [AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::middleware(['auth', 'active.session'])->group(function () {
    Route::view('/painel', 'dashboard')->name('dashboard');
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
    Route::middleware('can:users.manage')->group(function () {
        Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->names('users')->except(['show', 'destroy']);
        Route::resource('perfis', RoleController::class)->parameters(['perfis' => 'role'])->names('roles')->except(['show', 'destroy']);
    });
});
