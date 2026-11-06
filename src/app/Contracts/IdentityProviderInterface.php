<?php

namespace App\Contracts;

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
     *                    email, cpf, ...) o null si no existe.
     */
    public function findByCpf(string $cpf): ?array;

    /**
     * Busca un usuario por email (credencial en Adagio/Samba): devuelve los
     * datos del propietario o null si no existe / no se pudo consultar.
     */
    public function findByEmail(string $email): ?array;

    /**
     * @return bool true si ya existe una cuenta con ese email/login en el proveedor.
     */
    public function existsByEmail(string $email): bool;
}
