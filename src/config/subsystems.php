<?php

use App\Services\Subsystems\AdagioService;
use App\Services\Subsystems\ChatwootService;
use App\Services\Subsystems\EmailService;
use App\Services\Subsystems\EntraIdService;
use App\Services\Subsystems\GlpiService;
use App\Services\Subsystems\SambaAdService;
use App\Services\Subsystems\SlackService;

return [
    /*
    |--------------------------------------------------------------------
    | Drivers de subsistema
    |--------------------------------------------------------------------
    | Mapea el "slug" guardado en la tabla subsystems a la clase que
    | implementa SubsystemServiceInterface. Para agregar un subsistema
    | nuevo: crear la clase en app/Services/Subsystems, agregarla aquí,
    | y crear el registro correspondiente en la tabla subsystems.
    */
    'drivers' => [
        'adagio' => AdagioService::class,
        'glpi' => GlpiService::class,
        'chatwoot' => ChatwootService::class,
        'email' => EmailService::class,
        'slack' => SlackService::class,
        'entraid' => EntraIdService::class,
        'sambaad' => SambaAdService::class,
    ],

    // Slug del subsistema que actúa como proveedor de identidad (Adagio).
    'proveedor_identidad_slug' => 'adagio',
];
