<?php

namespace Tests\Feature\Subsystems\Accounts;

use Tests\Feature\Subsystems\SubsystemTestCase;

class DeleteAccountTest extends SubsystemTestCase
{
    public function test_a_gestor_user_account_can_be_deleted_locally(): void
    {
        $account = $this->makeAccount('email');
        $user = $account->user;

        $this->delete(route('gestor-users.accounts.destroy', [$user, $account]))
            ->assertRedirect(route('gestor-users.accounts.index', $user))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('user_subsystem_accounts', ['id' => $account->id]);
    }
}
