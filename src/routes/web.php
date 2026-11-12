<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GestorUserController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProvisioningOperationController;
use App\Http\Controllers\SubsystemController;
use App\Http\Controllers\TermoController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserSubsystemAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('verified')->name('dashboard');

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
        'gestor-users/{gestorUser}/suspend-manager',
        [GestorUserController::class, 'suspendManager']
    )->name('gestor-users.suspend-manager');

    Route::post(
        'gestor-users/{gestorUser}/reactivate-manager',
        [GestorUserController::class, 'reactivateManager']
    )->name('gestor-users.reactivate-manager');

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

    // El detalle de un medio del gestor, en el inventario de GLPI. Va dentro de
    // {gestorUser} por la misma razón que el polling: que el medio sea de esta
    // persona es una condición de la ruta, no algo que el controlador pueda
    // comprobar con datos fiables si no viene en la URL.
    Route::get(
        'gestor-users/{gestorUser}/medios/{tipo}/{id}',
        [GestorUserController::class, 'medio']
    )->name('gestor-users.medios.show');

    // Termos de responsabilidad. Van dentro de {gestorUser} por la misma razón
    // que los medios: el documento pertenece a una persona, y la ruta lo dice.
    Route::prefix('gestor-users/{gestorUser}/termos')
        ->name('gestor-users.termos.')
        ->group(function () {
            Route::get('formulario/{tipo}/{id}', [TermoController::class, 'formulario'])
                ->name('formulario');

            Route::post('/', [TermoController::class, 'store'])->name('store');

            Route::get('{termo}/pdf', [TermoController::class, 'pdfGenerado'])
                ->whereNumber('termo')
                ->name('pdf');

            Route::post('{termo}/enviado', [TermoController::class, 'marcarEnviado'])
                ->whereNumber('termo')
                ->name('enviado');

            Route::post('{termo}/firmado', [TermoController::class, 'subirFirmado'])
                ->whereNumber('termo')
                ->name('firmado');

            Route::get('{termo}/firmado/pdf', [TermoController::class, 'pdfFirmado'])
                ->whereNumber('termo')
                ->name('firmado.pdf');

            Route::post('{termo}/anular', [TermoController::class, 'anular'])
                ->whereNumber('termo')
                ->name('anular');
        });

    Route::resource('gestor-users', GestorUserController::class)
        ->parameters(['gestor-users' => 'gestorUser']);

    Route::post(
        'gestor-users/{gestorUser}/accounts/bulk',
        [UserSubsystemAccountController::class, 'storeMany']
    )->name('gestor-users.accounts.store-many');

    Route::resource('gestor-users.accounts', UserSubsystemAccountController::class)
        ->parameters([
            'gestor-users' => 'gestorUser',
            'accounts' => 'userSubsystemAccount',
        ]);

    Route::get('subsystems/{subsystem}/chatwoot/teams', [SubsystemController::class, 'chatwootTeams'])
        ->name('subsystems.chatwoot.teams');

    Route::post('subsystems/{subsystem}/test-connection', [SubsystemController::class, 'testConnection'])
        ->name('subsystems.test-connection');

    Route::post('subsystems/test-connections', [SubsystemController::class, 'testConnections'])
        ->name('subsystems.test-connections');

    Route::post('subsystems/{subsystem}/accounts/{userSubsystemAccount}/action', [SubsystemController::class, 'accountAction'])
        ->name('subsystems.accounts.action');

    Route::resource('subsystems', SubsystemController::class);
    Route::resource('operaciones', ProvisioningOperationController::class)->only(['index', 'show']);

});

require __DIR__.'/auth.php';
