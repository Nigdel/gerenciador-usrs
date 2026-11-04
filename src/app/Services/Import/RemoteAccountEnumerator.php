<?php

namespace App\Services\Import;

use App\Models\Subsystem;

/**
 * Enumera las cuentas de usuario de una plataforma remota, sin escribir nada.
 *
 * Es la mitad de solo lectura que introduce la importación masiva: los
 * drivers de app/Services/Subsystems siguen siendo la única capa de
 * edición y no se tocan para no arriesgar el provisioning. Cada enumerator
 * reutiliza la autenticación y los helpers de su driver extendiéndolo.
 */
interface RemoteAccountEnumerator
{
    public function slug(): string;

    /**
     * @return list<RemoteAccount>
     *
     * @throws \RuntimeException si la plataforma no se puede consultar: "no
     *                           puedo listar" no es lo mismo que "no hay cuentas", así que se
     *                           lanza y el importador decide si abortar o continuar.
     */
    public function enumerar(Subsystem $subsystem): array;
}
