<?php

namespace Tests\Feature;
use Illuminate\Foundation\Testing\RefreshDatabase;

use Tests\TestCase;
use App\Models\User;

class UserTest extends TestCase
{
    use RefreshDatabase;
    public function test_users_endpoint_returns_a_successful_response(): void
    {
        $response = $this->getJson('/users');

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                '*' => [ 'id', 'name', 'email', ],
            ]);
    }
    public function test_users_endpoint_returns_a_list_of_users(): void 
    { 
        User::factory()->create([ 
            'name' => 'Juan Pérez',
            'email' => 'juan.perez@example.com', ]);
        
        User::factory()->create([ 
            'name' => 'Nigdel Pena',
            'email' => 'nigdel.pena@example.com', ]);
        
        $response = $this->getJson('/users'); 
        $response 
            ->assertStatus(200) 
            ->assertJsonStructure([
                 '*' => [ 'id', 'name', 'email', ], 
                 ])
             ->assertJsonFragment([ 'name' => 'Nigdel Pena', 'email' => 'nigdel.pena@example.com', ])
             ->assertJsonFragment([ 'name' => 'Juan Pérez', 'email' => 'juan.perez@example.com', ]); 
    }
}