<?php

use App\Enums\ApiAbility;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\OperationController;
use App\Http\Controllers\Api\UserLookupController;
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

/*
 * Sonda pública de salud: es lo único que no exige token, porque un
 * orquestador tiene que poder consultarla antes de que exista una sesión.
 * El throttle de /api/* sigue aplicando.
 */
Route::get('/health', [HealthController::class, 'show'])->name('api.health');

Route::middleware('auth:sanctum')->group(function () {
    /*
     * Catálogo de consulta: cualquier token válido puede leerlo, sin ability
     * específica. No modifica nada y una integración lo necesita para saber a
     * qué subsistemas puede apuntar y con qué configuración adicional.
     */
    Route::get('/subsistemas', [CatalogController::class, 'subsystems'])->name('api.subsistemas.index');
    Route::get('/subsistema/{subsistema}', [CatalogController::class, 'subsystem'])->name('api.subsistemas.show');
    Route::get('/empresas', [CatalogController::class, 'companies'])->name('api.empresas.index');

    Route::prefix('usuarios')->group(function () {
        /*
         * Consulta previa al alta: dice si el CPF ya es un GestorUser, si solo
         * existe en Adagio, o si no está en ninguno de los dos. Ability aparte
         * de la de provisionar porque es de solo lectura.
         */
        Route::get('/consultar', [UserLookupController::class, 'show'])
            ->middleware('abilities:'.ApiAbility::ConsultarUsuario->value)
            ->name('api.usuarios.consultar');

        // Con nombre para que los tests y la documentación puedan referirse a
        // la ruta sin escribir el path: el prefijo y el verbo ya se ven.
        Route::post('/provisionar', [UserProvisioningController::class, 'store'])
            ->middleware([
                'idempotencia',
                'abilities:'.ApiAbility::Provisionar->value,
            ])
            ->name('api.usuarios.provisionar');

        Route::post('/suspender', [UserSuspensionController::class, 'store'])
            ->middleware([
                'idempotencia',
                'abilities:'.ApiAbility::Suspender->value,
            ])
            ->name('api.usuarios.suspender');
    });

    /*
     * Consulta de una operación por su uuid (Sprint 1.3). Cierra el circuito
     * del alta: el POST devuelve el `operacion_id` y encola el trabajo, así que
     * sin esto la integración no puede saber si las cuentas se crearon.
     *
     * Ability aparte de las de escritura a propósito: consultar es de sobra
     * más seguro que dar de alta, y una integración que solo informa del estado
     * no debería poder crear usuarios por el mismo token.
     */
    Route::get('/operaciones/{uuid}', [OperationController::class, 'show'])
        ->middleware('abilities:'.ApiAbility::Consultar->value)
        ->name('api.operaciones.show');
});
