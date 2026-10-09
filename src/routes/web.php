<?php

use App\Http\Controllers\GestorUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SubsystemController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSubsystemAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->middleware('verified')->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Rutas protegidas del Gestor de Usuarios
    Route::resource('users', UserController::class);

    Route::get('gestor-users/lookup-cpf', [GestorUserController::class, 'lookupByCpf'])
        ->name('gestor-users.lookup-cpf');

    Route::post(
        'gestor-users/{gestorUser}/suspend',
        [GestorUserController::class, 'suspend']
    )->name('gestor-users.suspend');

    Route::post(
        'gestor-users/{gestorUser}/reset-password',
        [GestorUserController::class, 'resetPassword']
    )->name('gestor-users.reset-password');

    Route::post(
        'gestor-users/{gestorUser}/offboard',
        [GestorUserController::class, 'offboard']
    )->name('gestor-users.offboard');

    Route::post(
        'gestor-users/{gestorUser}/reactivate',
        [GestorUserController::class, 'reactivate']
    )->name('gestor-users.reactivate');

    Route::post(
        'gestor-users/{gestorUser}/sync-subsystems',
        [GestorUserController::class, 'syncSubsystems']
    )->name('gestor-users.sync-subsystems');

    // Consulta el estado de una operación para el polling del panel (Fase
    // 3.2). Va dentro de {gestorUser} a propósito: comprobar que la operación
    // es de ese usuario es una condición de la ruta, no algo que se pueda
    // dejar para el controlador.
    Route::get(
        'gestor-users/{gestorUser}/operaciones/{operacion}',
        [GestorUserController::class, 'operacion']
    )->name('gestor-users.operaciones.show');

    // Reintenta una cuenta que falló (Sprint 1.2). El alcance por usuario es
    // la misma condición de ruta que en el polling, y por el mismo motivo: que
    // la fila sea de una operación de esta persona no es algo que pueda
    // comprobar el controlador con datos fiables si no viene en la URL.
    Route::post(
        'gestor-users/{gestorUser}/operaciones/{operacion}/cuentas/{cuenta}/retry',
        [GestorUserController::class, 'reintentar']
    )->name('gestor-users.operaciones.retry');

    Route::resource('gestor-users', GestorUserController::class)
        ->parameters(['gestor-users' => 'gestorUser']);

    Route::resource('gestor-users.accounts', UserSubsystemAccountController::class)
        ->parameters([
            'gestor-users' => 'gestorUser',
            'accounts' => 'userSubsystemAccount',
        ]);

    Route::get('subsystems/{subsystem}/chatwoot/teams', [SubsystemController::class, 'chatwootTeams'])
        ->name('subsystems.chatwoot.teams');

    Route::post('subsystems/{subsystem}/test-connection', [SubsystemController::class, 'testConnection'])
        ->name('subsystems.test-connection');

    Route::post('subsystems/{subsystem}/accounts/{userSubsystemAccount}/action', [SubsystemController::class, 'accountAction'])
        ->name('subsystems.accounts.action');

    Route::resource('operaciones', \App\Http\Controllers\ProvisioningOperationController::class)->only(['index', 'show']);
});

require __DIR__.'/auth.php';
