<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\AdagioService;

/**
 * Enumera las cuentas de Adagio, el proveedor de identidad.
 *
 * Adagio no expone un listado completo sin parámetros: la única vía es
 * buscar por CPF o por email. Por eso este enumerador se usa en los tests
 * y en la asociación de gestores ya conocidos (vía cpf→datos); en el
 * flujo de importación, las cuentas de Adagio se construyen bajo demanda
 * desde datosDeAdagio() y no desde una enumeración masiva.
 *
 * Extiende AdagioService para reutilizar la sesión JWT cacheada. Solo lee.
 */
class AdagioRemoteAccountEnumerator extends AdagioService implements RemoteAccountEnumerator
{
    public function slug(): string
    {
        return 'adagio';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        return [];
    }
}
