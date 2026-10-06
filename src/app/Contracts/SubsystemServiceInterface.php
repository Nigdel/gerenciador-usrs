<?php

namespace App\Contracts;

use App\DTO\SubsystemOperationResult;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;

/**
 * Contrato universal. Cada subsistema (Adagio, Glpi, Chatwoot, Email, Slack,
 * EntraId, SambaAd, ...) implementa esta interfaz con su propia lógica,
 * pero el orquestador (UserProvisioningService / UserSuspensionService)
 * siempre invoca estos mismos métodos sin conocer los detalles internos.
 */
interface SubsystemServiceInterface
{
    /**
     * Crea el usuario en el subsistema.
     *
     * @param  array  $userData  Datos ya resueltos del usuario: nombre_completo, cpf,
     *                           usuario (login propuesto/reutilizado), email_personal,
     *                           empresa, password_general, etc.
     */
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult;

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult;

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult;

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult;

    public function supportsDeleteUser(): bool;

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult;

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult;

    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult;

    /**
     * Si el driver sabe actualizar los datos de contacto en remoto.
     *
     * Va aparte de updateUser() a propósito: permite distinguir "este subsistema
     * no lo admite" de "este subsistema falló", que para el operador son cosas
     * muy distintas (reintentar o no tiene sentido en la primera).
     */
    public function supportsUpdateUser(): bool;

    /**
     * Propaga al subsistema los datos de contacto del usuario (Fase 2.7).
     *
     * Es **opcional** y va por el mismo patrón que deleteUser(): los drivers
     * que no puedan actualizar los datos en remoto no sobrescriben el método y
     * heredan el de la base, que devuelve un fallo explicando que el subsistema
     * no lo admite. Así un driver que no se toca nunca dice "sincronizado" por
     * accidente.
     *
     * Solo se sincronizan **atributos de contacto** (nombre, email, teléfonos,
     * dirección). Deliberadamente NO el login, la empresa ni el CPF: en los
     * subsistemas son la clave con la que se creó la cuenta (sAMAccountName,
     * userPrincipalName, dirección del mailbox, employeeId), y renombrarlos es
     * otra operación, con otros riesgos.
     *
     * @param  array  $userData  Los datos del GestorUser ya resueltos.
     */
    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult;
}
