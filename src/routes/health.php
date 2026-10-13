<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sonda de salud
|--------------------------------------------------------------------------
|
| Vive fuera de routes/web.php a propósito: no lleva el middleware `web`, así
| que no arrastra cookies ni sesión, y responde igual aunque nobody haya
| iniciado sesión. Para un healthcheck eso no es un detalle — si la sonda
| compartiera middleware con la web, un problema de sesiones que no
| tuviera que ver con la salud del servicio lo haría parecer caído.
|
| Comprueba también la base de datos y no solo que PHP responde: MySQL es
| donde viven la cola, la caché y las sesiones, así que una web que contesta
| 200 sin MySQL está sirviendo páginas de error, no funcionando.
|
| Se responde 503 cuando la base no contesta, que es lo que un orquestador
| necesita para dejar de enrutar tráfico hacia esta instancia.
|
*/

Route::get('/healthz', function () {
    try {
        DB::select('select 1');
    } catch (Throwable $exception) {
        report($exception);

        return response()->json([
            'status' => 'error',
            'database' => 'desconectada',
        ], 503);
    }

    return response()->json([
        'status' => 'ok',
        'database' => 'ok',
    ]);
})->name('healthz');
