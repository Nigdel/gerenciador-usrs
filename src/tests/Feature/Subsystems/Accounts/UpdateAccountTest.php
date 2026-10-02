<?php

namespace Tests\Feature\Subsystems\Accounts;

use Tests\Feature\Subsystems\SubsystemTestCase;

class UpdateAccountTest extends SubsystemTestCase
{
    public function test_a_gestor_user_account_can_be_updated(): void
    {
        $account = $this->makeAccount('glpi');
        $user = $account->user;

        $this->put(route('gestor-users.accounts.update', [$user, $account]), [
            'subsystem_id' => $account->subsystem_id,
            'credencial_usuario' => 'ana.actualizada',
            'external_account_id' => 'glpi-43',
            'estado' => 'suspendido',
        ])->assertRedirect(route('gestor-users.accounts.index', $user))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('user_subsystem_accounts', [
            'id' => $account->id,
            'credencial_usuario' => 'ana.actualizada',
            'external_account_id' => 'glpi-43',
            'estado' => 'suspendido',
        ]);
    }
}
