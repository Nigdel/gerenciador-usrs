<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\OperationInProgressException;
use App\Exceptions\ProvisioningException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Mapeo único de errores a respuestas HTTP para la API de provisionado
 * (Sprint 2.1).
 *
 * Vive en un trait y no en una clase base porque las tres acciones de la API
 * no comparten nada más: solo coinciden en esto. Y vive en un trait y no en
 * `bootstrap/app.php` porque el 422 y el 502 dependen de *qué* falló, no de
 * qué clase es la excepción —el mismo `ProvisioningException` es un 422 en un
 * sitio y se come el 409 en otro—, y el render global solo puede mirar la
 * clase.
 *
 * La respuesta nunca lleva el mensaje de una excepción que no sea de dominio.
 * Un 500 con la traza dentro sería justo lo que una integración no debería
 * recibir: datos de la infraestructura en la respuesta y, en el caso de una
 * `ConnectionException`, la URL y a veces las cabeceras del subsistema.
 */
trait RespondeErroresDeProvisionado
{
    /**
     * Deja el trait listo al construirse el controlador.
     *
     * El método no hace nada y está aquí por legibilidad: leer el constructor
     * y ver que solo reenvía al trait deja claro que todo el manejo de errores
     * vive en un sitio, en vez de repartido entre una línea del constructor y
     * el cuerpo de la acción.
     */
    private function usaRespuestasControladas(): void
    {
        //
    }

    /**
     * Envuelve el cuerpo de la acción y traduce lo que se escape.
     *
     * @param  callable(): JsonResponse  $accion
     */
    private function conErroresControlados(callable $accion): JsonResponse
    {
        try {
            return $accion();
        } catch (OperationInProgressException $exception) {
            // Se deja pasar hacia bootstrap/app.php, que responde 409 con el
            // identificador de la operación que bloquea. Aquí solo se evita que
            // caiga en el 500 genérico de abajo.
            throw $exception;
        } catch (ModelNotFoundException $exception) {
            // Un usuario que no está en la base no es un problema de
            // infraestructura. Localizarlo usa firstOrFail(), así que sin esto
            // caía en el 502 de abajo y la integración leía «el servicio no
            // está disponible» cuando lo único que pasa es que el CPF no
            // existe — que además no se puede arreglar reintentando.
            //
            // Solo aquí: en OperationController, un firstOrFail() sí debe
            // seguir siendo 404, porque una operación que no existe no se
            // distingue de una que es de otro.
            return response()->json([
                'message' => 'No se encontró ningún usuario con el identificador indicado.',
            ], 422);
        } catch (ProvisioningException $exception) {
            // Los datos eran válidos pero la situación no lo permite: es un
            // 422 y no un 400, porque el mismo payload puede aceptarse más
            // adelante sin tocar un solo campo.
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            report($exception);

            // Todo lo demás es infraestructura: Adagio o un subsistema que no
            // responde, la extensión ldap que no está cargada, un error de
            // programación. Para el cliente es lo mismo —"no se pudo hacer,
            // inténtalo luego"— y el detalle se queda en el log.
            return response()->json([
                'message' => 'No se pudo completar la operación: el servicio no está disponible.',
            ], 502);
        }
    }
}
