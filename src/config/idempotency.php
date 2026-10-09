<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ventana de retención de las claves de idempotencia
    |--------------------------------------------------------------------------
    |
    | Cuánto tiempo se recuerda una clave antes de poder reutilizarse. No es
    | solo limpieza: es lo que define durante cuánto una integración puede
    | reintentar con seguridad tras un timeout. Pasado ese plazo, la misma
    | clave se trata como nueva y el alta se repetiría.
    |
    | Por eso el valor es generoso (24 h) y no el mínimo que «se vea limpio en
    | la tabla»: es preferible una fila de más a un alta duplicada.
    */

    'ttl_hours' => (int) env('IDEMPOTENCY_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Rutas protegidas
    |--------------------------------------------------------------------------
    |
    | Solo las acciones que crean algo necesitan idempotencia. Consultar una
    | operación con la misma clave dos veces no hace daño, y exigir la cabecera
    | ahí obligaría a la integración a inventar una en cada GET.
    |
    | Las rutas se nombran aquí en vez de repetir `->middleware('idempotencia')`
    | en cada una porque el middleware tiene que ir antes de la validación: si
    | el payload es distinto, tiene que contestar 422 aunque el resto fuera
    | válido, y FormRequest no puede saberlo.
    */

    'rutas' => [
        'usuarios/provisionar',
        'usuarios/suspender',
    ],

    /*
    |--------------------------------------------------------------------------
    | Estado «en curso»
    |--------------------------------------------------------------------------
    |
    | Qué se responde cuando la misma clave llega mientras la primera petición
    | todavía se está ejecutando. No es un 409: no hay conflicto con el estado
    | del negocio, es que la respuesta anterior todavía no existe. 425 (Too
    | Early) es exactamente eso, y volver a intentarlo un segundo después
    | devuelve la respuesta ya guardada.
    */

    'status_en_curso' => 425,

];
