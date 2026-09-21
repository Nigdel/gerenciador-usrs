<?php

namespace App\Services;

use App\Contracts\IdentityProviderInterface;
use App\Models\Subsystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Propone el login/usuario para un usuario nuevo (que no existe en Adagio
 * por CPF), siguiendo la convención nombre.primerApellido@empresa.com.br,
 * validando disponibilidad contra Adagio (proveedor de identidad).
 */
class UsernameGeneratorService
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {
    }

    /**
     * @return string Login propuesto, ej: "juan.perez" (sin el dominio de email).
     */
    public function proponer(string $nombreCompleto, string $empresa): string
    {
        [$nombre, $apellido1, $apellido2] = $this->descomponerNombre($nombreCompleto);

        $dominio = $this->resolverDominio($empresa);
        $identityProvider = $this->resolverProveedorIdentidad();

        $candidatoPrincipal = $this->normalizar($nombre).'.'.$this->normalizar($apellido1);

        if (! $apellido2 || ! $identityProvider->existsByEmail($candidatoPrincipal.'@'.$dominio)) {
            return $candidatoPrincipal;
        }

        // El email con el primer apellido ya está en uso: se intenta con el segundo apellido.
        $candidatoSecundario = $this->normalizar($nombre).'.'.$this->normalizar($apellido2);

        if (! $identityProvider->existsByEmail($candidatoSecundario.'@'.$dominio)) {
            return $candidatoSecundario;
        }

        // Última salvaguarda: ambos candidatos ya existen. Se agrega un sufijo
        // numérico incremental en vez de fallar silenciosamente.
        $sufijo = 2;
        do {
            $candidatoConSufijo = $candidatoSecundario.$sufijo;
            $sufijo++;
        } while ($identityProvider->existsByEmail($candidatoConSufijo.'@'.$dominio) && $sufijo < 100);

        return $candidatoConSufijo;
    }

    /**
     * @return array{0: string, 1: string, 2: ?string} [nombre, primer_apellido, segundo_apellido|null]
     */
    private function descomponerNombre(string $nombreCompleto): array
    {
        $partes = array_values(array_filter(explode(' ', trim($nombreCompleto))));

        if (count($partes) < 2) {
            throw new RuntimeException('El nombre completo debe incluir al menos nombre y un apellido');
        }

        if (count($partes) === 2) {
            return [$partes[0], $partes[1], null];
        }

        // Convención: último token = segundo apellido, penúltimo = primer apellido,
        // todo lo anterior se considera parte del nombre (pero solo se usa el primero).
        $segundoApellido = array_pop($partes);
        $primerApellido = array_pop($partes);
        $nombre = $partes[0];

        return [$nombre, $primerApellido, $segundoApellido];
    }

    private function normalizar(string $texto): string
    {
        return Str::of($texto)->lower()->ascii()->replace(' ', '')->toString();
    }

    private function resolverDominio(string $empresa): string
    {
        // Ajustable: puede resolverse desde una tabla de empresas/config en vez de aquí.
        return $this->normalizar($empresa).'.com.br';
    }

    private function resolverProveedorIdentidad(): IdentityProviderInterface
    {
        $slug = config('subsystems.proveedor_identidad_slug', 'adagio');
        $servicio = $this->registry->resolve($slug);

        if (! $servicio instanceof IdentityProviderInterface) {
            throw new RuntimeException("El subsistema proveedor de identidad [{$slug}] debe implementar IdentityProviderInterface");
        }

        return $servicio;
    }
}
