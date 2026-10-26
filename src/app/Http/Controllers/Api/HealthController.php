<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GET /api/health — sonda pública de estado del sistema.
 *
 * No expone credenciales ni detalles internos: solo si la base de datos, la
 * caché y el almacenamiento responden. Devuelve 503 cuando algo falla para que
 * un balanceador pueda sacar la instancia de rotación sin interpretar el JSON.
 */
class HealthController extends Controller
{
    public function show(): JsonResponse
    {
        $comprobaciones = [
            'base_de_datos' => $this->comprobar(fn () => DB::select('select 1')),
            'cache' => $this->comprobar(function (): void {
                $clave = 'health:'.bin2hex(random_bytes(4));
                Cache::put($clave, 'ok', 10);

                if (Cache::get($clave) !== 'ok') {
                    throw new \RuntimeException('La caché no devolvió el valor escrito.');
                }

                Cache::forget($clave);
            }),
            'almacenamiento' => is_writable(storage_path()) ? 'ok' : 'error',
        ];

        $estado = in_array('error', $comprobaciones, true) ? 'error' : 'ok';

        return response()->json([
            'estado' => $estado,
            'fecha' => now()->toIso8601String(),
            'comprobaciones' => $comprobaciones,
            'aplicacion' => [
                'nombre' => config('app.name'),
                'version_php' => PHP_VERSION,
                'version_laravel' => app()->version(),
            ],
        ], $estado === 'ok' ? 200 : 503);
    }

    /**
     * Ejecuta una comprobación y traduce cualquier fallo a 'error' sin sacar
     * el mensaje de la excepción en la respuesta pública.
     */
    private function comprobar(callable $comprobacion): string
    {
        try {
            $comprobacion();

            return 'ok';
        } catch (Throwable $exception) {
            report($exception);

            return 'error';
        }
    }
}
