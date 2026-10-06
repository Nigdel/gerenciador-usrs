<?php

use App\Enums\ApiAbility;
use App\Http\Controllers\Api\UserProvisioningController;
use App\Http\Controllers\Api\UserSuspensionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Gestor de Usuarios
|--------------------------------------------------------------------------
| Autenticadas con un token de Sanctum. Cada endpoint exige además la ability
| correspondiente: el rol del usuario dueño del token no abre la puerta por sí
| solo, solo las abilities emitidas.
|
| Emitir un token:
|   php artisan api:token {integracion} --abilities=usuarios:provisionar,usuarios:suspender
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('usuarios')->group(function () {
        Route::post('/provisionar', [UserProvisioningController::class, 'store'])
            ->middleware('abilities:'.ApiAbility::Provisionar->value);

        Route::post('/suspender', [UserSuspensionController::class, 'store'])
            ->middleware('abilities:'.ApiAbility::Suspender->value);
    });
});
