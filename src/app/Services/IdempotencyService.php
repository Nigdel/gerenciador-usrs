<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sprint 5.3 — Idempotencia de la API.
 *
 * Una integración que llama a /provisionar y pierde la respuesta en el camino
 * tiene que poder repetir la llamada sin crear un usuario dos veces. Eso se
 * resuelve con la cabecera `Idempotency-Key`: la primera petición guarda su
 * resultado y las siguientes con la misma clave reciben ese mismo resultado.
 *
 * La regla que hace que esto sea seguro y no solo cómodo es el hash del
 * payload: la clave identifica a la operación, pero si el cuerpo cambia la
 * petición ya no es la misma y responder con la respuesta guardada devolvería
 * un alta que nadie pidió. Por eso el cuerpo distinto es 422 y no una
 * repetición.
 *
 * Los estados intermedios importan tanto como los finales. La carrera entre
 * dos peticiones idénticas es real, así que la fila se crea ANTES de ejecutar
 * nada: la segunda ve que hay una fila sin respuesta y contesta 425 en vez de
 * trabajar en paralelo.
 */
class IdempotencyService
{
    /**
     * La clave es libre y no tiene un formato impuesto, pero sin cota es un
     * vector de dos cosas: una fila por petición sin límite de tamaño, y una
     * columna que se traga la petición entera. 255 es de sobra para lo que
     * generan los clientes reales.
     */
    public const MAX_LONGTUD_CLAVE = 255;

    /**
     * La primera petición con esta clave está corriendo: todavía no hay
     * respuesta que repetir.
     */
    public const EN_CURSO = 'en_curso';

    /**
     * Esta clave ya se usó y su respuesta está guardada.
     */
    public const REPETIR = 'repetir';

    /**
     * Esta clave ya se usó, pero con un cuerpo distinto.
     */
    public const CONFLICTO = 'conflicto';

    /**
     * La petición entra por primera vez: se puede ejecutar.
     */
    public const NUEVO = 'nuevo';

    /**
     * Decide qué hacer con la petición y reserva la clave si es nueva.
     *
     * Devuelve uno de los estados de arriba. Cuando devuelve NUEVO la fila ya
     * está creada, así que quien llama puede trabajar y después guardar el
     * resultado con guardarRespuesta().
     */
    public function reservar(Request $request, string $clave): array
    {
        $huella = $this->huella($request);

        // El INSERT es la operación atómica que decide quién gana. Se intenta
        // primero y, si choca con el índice único, es que otro la ha ocupado:
        // se relee y se decide desde lo que haya quedado.
        try {
            $fila = IdempotencyKey::create([
                'key' => $clave,
                'payload_hash' => $huella,
                'response' => null,
                'expires_at' => now()->addHours($this->ttl()),
            ]);

            return ['estado' => self::NUEVO, 'fila' => $fila];
        } catch (QueryException) {
            // Sigue el camino de «ya existía».
        }

        $existente = IdempotencyKey::where('key', $clave)->first();

        // Una fila caducada es indistinguible de no tener fila: se borra y se
        // vuelve a intentar, para que pasado el TTL la clave se pueda reutilizar
        // sin que nadie tenga que purgar la tabla a mano.
        if ($existente === null || $this->expirada($existente)) {
            if ($existente !== null) {
                $existente->delete();
            }

            return $this->reservar($request, $clave);
        }

        if (! hash_equals($existente->payload_hash, $huella)) {
            return ['estado' => self::CONFLICTO, 'fila' => $existente];
        }

        // Sin respuesta guardada, la primera petición sigue corriendo. Un 425
        // dice «vuelve en un momento» en lugar de dejar que la segunda haga el
        // mismo trabajo dos veces.
        if ($existente->response === null && $existente->error === null) {
            return ['estado' => self::EN_CURSO, 'fila' => $existente];
        }

        return ['estado' => self::REPETIR, 'fila' => $existente];
    }

    /**
     * Guarda la respuesta de la petición que se reservó la clave.
     *
     * Se guardan el cuerpo YA serializado y el código, no el objeto respuesta:
     * lo que se repite tiene que ser exactamente lo que se envió la primera
     * vez, y una reconstrucción podría salir distinta. Por eso el cuerpo
     * llega como string y no se vuelve a pasar por JsonResponse, que lo
     * serializaría otra vez y lo dejaría como un documento citado dentro de
     * una cadena.
     */
    public function guardarRespuesta(IdempotencyKey $fila, string $cuerpo, int $status): void
    {
        $fila->forceFill([
            'response' => $cuerpo,
            'respuesta_hash' => sha1($cuerpo),
            'status' => $status,
            'error' => null,
        ])->save();
    }

    /**
     * Registra que la petición terminó en error de infraestructura.
     *
     * Un 422 no llega aquí: ese error es de los datos, no hizo nada, y en
     * guardarError() se borra la fila para que la integración pueda repetir
     * con la misma clave tras corregir el campo.
     */
    public function guardarError(IdempotencyKey $fila, int $status, string $cuerpo): void
    {
        if ($status >= 400 && $status < 500) {
            $fila->delete();

            return;
        }

        $fila->forceFill([
            'response' => $cuerpo,
            'respuesta_hash' => sha1($cuerpo),
            'status' => $status,
            'error' => $cuerpo,
        ])->save();
    }

    /**
     * Reconstruye la respuesta guardada con su código original.
     *
     * Si algo se guardó a medias —una fila con `response` pero sin `status`,
     * por ejemplo— se devuelve 500 en vez de un cuerpo sin código, que sería
     * un 200 con basura.
     */
    public function repetir(IdempotencyKey $fila): JsonResponse
    {
        $status = $fila->status;

        if ($status === null) {
            report(new \RuntimeException("Fila de idempotencia {$fila->key} sin código de respuesta guardado."));

            return new JsonResponse(['message' => 'No se pudo recuperar la respuesta de la petición anterior.'], 500);
        }

        // Los bytes guardados se vuelven a enviar tal cual, con setContent() y
        // no passando el array a JsonResponse: pasar el string a JsonResponse
        // lo re-serializaría como una cadena JSON con comillas dentro, y el
        // cliente recibiría algo que no es el documento original.
        $respuesta = new JsonResponse(null, $status);
        $respuesta->setContent((string) $fila->response);

        return $respuesta;
    }

    /**
     * Borra las claves más viejas que la ventana de retención.
     *
     * Sin esto la tabla crece sin límite y, peor, una clave de hace un año
     * seguiría sirviendo su respuesta vieja: pasado el TTL el reintento debe
     * volver a ejecutarse.
     */
    public function purgar(?int $limite = null): int
    {
        return IdempotencyKey::query()
            ->where('expires_at', '<', now())
            ->when($limite, fn ($q) => $q->limit($limite))
            ->delete();
    }

    /**
     * La huella del cuerpo, con el orden de las claves normalizado.
     *
     * Sin normalizar, dos peticiones idénticas con las claves del JSON en otro
     * orden darían huellas distintas y la segunda se trataría como conflicto.
     * La integración manda el mismo cuerpo byte a byte casi siempre, pero
     * serializarlo desde PHP es lo que hace el hashing estable aquí.
     */
    private function huella(Request $request): string
    {
        $cuerpo = $request->getContent();
        $decodificado = json_decode($cuerpo, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decodificado)) {
            $cuerpo = json_encode($this->ordenar($decodificado));
        }

        return sha1((string) $cuerpo);
    }

    /**
     * @param  array<mixed>  $datos
     * @return array<mixed>
     */
    private function ordenar(array $datos): array
    {
        if (! array_is_list($datos)) {
            ksort($datos);
        }

        foreach ($datos as $clave => $valor) {
            if (is_array($valor)) {
                $datos[$clave] = $this->ordenar($valor);
            }
        }

        return $datos;
    }

    private function expirada(IdempotencyKey $fila): bool
    {
        return $fila->expires_at !== null && $fila->expires_at->isPast();
    }

    private function ttl(): int
    {
        return max(1, (int) config('idempotency.ttl_hours', 24));
    }
}
