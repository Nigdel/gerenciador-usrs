<?php
namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;


class UserTest extends TestCase
{
    public function test_user_has_a_name_and_email(): void
    {
        $name = "Juan Pérez";
        $email = "juan.perez@example.com";

        $user = new User([
            'name' => $name,
            'email' => $email,
        ]);
        $this->assertNotEmpty($user->name);
        $this->assertNotEmpty($user->email);
    }
}
