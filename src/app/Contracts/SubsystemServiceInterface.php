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
     *                            usuario (login propuesto/reutilizado), email_personal,
     *                            empresa, password_general, etc.
     */
    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult;

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult;

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult;

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult;

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult;
}
