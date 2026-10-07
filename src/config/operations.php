<?php

return [

    /*
     |--------------------------------------------------------------------------
     | TTL de los secretos del payload
     |--------------------------------------------------------------------------
     |
     | El payload de una operación va cifrado, pero cifrado no es lo mismo que
     | inocuo: guarda la contraseña general en claro durante todo el tiempo que
     | la operación exista. Estas horas son el máximo que se conserva en una
     | operación ya terminada. Pasado ese plazo, `operations:prune-secrets` la
     | borra.
     |
     | Hay que elegir un plazo que cubra de sobra el tiempo que un operador
     | necesita para reintentar un alta fallida, porque hasta que no se reintenta
     | no hay otra copia de la contraseña: si se poda antes, el reintento ya no
     | podrá crearla en el subsistema y habría que resetearla a mano.
     |
     */
    'secret_ttl_hours' => (int) env('OPERATIONS_SECRET_TTL_HOURS', 72),

    /*
     |--------------------------------------------------------------------------
     | Operaciones atascadas
     |--------------------------------------------------------------------------
     |
     | Minutos sin actividad tras los cuales una operación 'en curso' se da por
     | muerta y se marca como fallida, para que no bloquee al usuario a la
     | espera de un reintento que ya no va a llegar. Lo usa
     | `operations:expire-stuck` (Sprint 1.4).
     */
    'stuck_minutes' => (int) env('OPERATIONS_STUCK_MINUTES', 30),

];
