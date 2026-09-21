<?php

use App\Http\Controllers\Api\UserProvisioningController;
use App\Http\Controllers\Api\UserSuspensionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rutas del Gestor de Usuarios
|--------------------------------------------------------------------------
| Agrega estas líneas a tu routes/api.php existente (dentro del middleware
| de autenticación/autorización que uses, ej. ->middleware('auth:sanctum')).
*/

Route::prefix('usuarios')->group(function () {
    Route::post('/provisionar', [UserProvisioningController::class, 'store']);
    Route::post('/suspender', [UserSuspensionController::class, 'store']);
});
