<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ClinicalController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\PatientController;
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
    Route::get('/painel', [AppointmentController::class, 'dashboard'])->name('dashboard');
    Route::get('/painel/fila', [AppointmentController::class, 'queue'])->name('appointments.queue');
    Route::resource('pacientes', PatientController::class)->parameters(['pacientes' => 'patient'])->names('patients')->except(['show', 'destroy'])->middleware('can:patients.manage');
    Route::get('/agenda', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::middleware('can:appointments.manage')->group(function () {
        Route::get('/agenda/nova', [AppointmentController::class, 'create'])->name('appointments.create');
        Route::post('/agenda', [AppointmentController::class, 'store'])->name('appointments.store');
        Route::get('/agenda/{appointment}/editar', [AppointmentController::class, 'edit'])->name('appointments.edit');
        Route::put('/agenda/{appointment}', [AppointmentController::class, 'update'])->name('appointments.update');
        Route::post('/bloqueios', [AppointmentController::class, 'block'])->name('blocks.store');
        Route::delete('/bloqueios/{block}', [AppointmentController::class, 'unblock'])->name('blocks.destroy');
    });
    Route::post('/agenda/{appointment}/situacao', [AppointmentController::class, 'transition'])->name('appointments.transition');
    Route::get('/cobrancas/hoje', [BillingController::class, 'today'])->name('billing.today')->middleware('can:billing.today');
    Route::get('/cobrancas/{charge}', [BillingController::class, 'show'])->name('billing.show');
    Route::post('/cobrancas/{charge}/recebimentos', [BillingController::class, 'receive'])->name('billing.receive');
    Route::middleware('can:finance.manage')->group(function () {
        Route::get('/financeiro', [BillingController::class, 'index'])->name('finance.index');
        Route::get('/financeiro/atendimentos', [BillingController::class, 'types'])->name('finance.types');
        Route::post('/financeiro/atendimentos', [BillingController::class, 'saveType'])->name('finance.types.store');
        Route::put('/financeiro/atendimentos/{type}', [BillingController::class, 'saveType'])->name('finance.types.update');
        Route::put('/cobrancas/{charge}', [BillingController::class, 'adjust'])->name('finance.adjust');
        Route::post('/cobrancas/{charge}/estornos/{payment}', [BillingController::class, 'reverse'])->name('finance.reverse');
    });
    Route::middleware('can:clinical.manage')->group(function () {
        Route::get('/pacientes/{patient}/prontuario', [ClinicalController::class, 'index'])->name('clinical.index');
        Route::post('/pacientes/{patient}/prontuario', [ClinicalController::class, 'store'])->name('clinical.store');
        Route::get('/pacientes/{patient}/prontuario/{entry}', [ClinicalController::class, 'show'])->name('clinical.show');
        Route::post('/pacientes/{patient}/prontuario/{entry}/revisoes', [ClinicalController::class, 'revise'])->name('clinical.revise');
    });
    Route::middleware('can:documents.manage')->group(function () {
        Route::get('/pacientes/{patient}/documentos', [DocumentController::class, 'index'])->name('documents.index');
        Route::post('/pacientes/{patient}/documentos', [DocumentController::class, 'store'])->name('documents.store');
        Route::get('/pacientes/{patient}/documentos/{document}/download', [DocumentController::class, 'download'])->name('documents.download');
        Route::put('/pacientes/{patient}/documentos/{document}/arquivo', [DocumentController::class, 'archive'])->name('documents.archive');
    });
    Route::post('/sair', [AuthController::class, 'logout'])->name('logout');
    Route::middleware('can:users.manage')->group(function () {
        Route::resource('usuarios', UserController::class)->parameters(['usuarios' => 'user'])->names('users')->except(['show', 'destroy']);
        Route::resource('perfis', RoleController::class)->parameters(['perfis' => 'role'])->names('roles')->except(['show', 'destroy']);
    });
});
