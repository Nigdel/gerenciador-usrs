<?php

namespace App\Contracts;

use App\Models\Subsystem;

/**
 * Contrato adicional, solo para el subsistema marcado como "proveedor de
 * identidad" (es_proveedor_identidad = true), típicamente Adagio. Permite
 * buscar por CPF y por email al proponer un nuevo usuario, sin acoplar
 * UsernameGeneratorService a una implementación concreta.
 */
interface IdentityProviderInterface extends SubsystemConnectionInterface
{
    /**
     * @return array|null Datos del usuario en el proveedor (nombre_completo,
     *                     email, cpf, ...) o null si no existe.
     */
    public function findByCpf(string $cpf): ?array;

    /**
     * @return bool true si ya existe una cuenta con ese email/login en el proveedor.
     */
    public function existsByEmail(string $email): bool;
}
