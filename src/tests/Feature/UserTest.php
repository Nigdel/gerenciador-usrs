<?php
namespace tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        User::factory()->create([
            'name' => 'Juan Pérez',
            'email' => 'juaan.perez@example.com',
        ]);

        User::factory()->create([
            'name' => 'María González',
            'email' => 'maria.glz@example.com',
        ]);

        $response = $this->getJson('/users');

        $response
            ->assertStatus(200)
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'name',
                    'email',
                ],
            ])
            ->assertJsonFragment([
                'name' => 'Juan Pérez',
                'email' => 'juaan.perez@example.com',
            ])
            ->assertJsonFragment([
                'name' => 'María González',
                'email' => 'maria.glz@example.com',
            ]);
    }

    public function test_users_index_returns_html_when_requested(): void
    {
        User::factory()->create([
            'name' => 'Usuário HTML',
            'email' => 'html@example.com',
        ]);

        $response = $this->get('/users', [
            'Accept' => 'text/html',
        ]);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertViewIs('users.index')
            ->assertSee('Usuário HTML')
            ->assertSee('html@example.com');
    }

    public function test_a_user_can_be_created(): void
    {
        $encarregado = User::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana.silva@example.com',
        ]);

        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'carlos.rodriguez@example.com',
            'password' => 'Password123!',
            'cpf' => '12345678901',
            'telefone_pessoal' => '62999999999',
            'telefone_servico' => '6233333333',
            'empresa' => 'Empresa Teste',
            'cargo' => 'Analista de TI',
            'externo' => false,
            'encarregado_id' => $encarregado->id,
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'name',
                'email',
                'cpf',
                'telefone_pessoal',
                'telefone_servico',
                'empresa',
                'cargo',
                'externo',
            ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'carlos.rodriguez@example.com',
            'cpf' => '12345678901',
            'telefone_pessoal' => '62999999999',
            'telefone_servico' => '6233333333',
            'empresa' => 'Empresa Teste',
            'cargo' => 'Analista de TI',
            'externo' => false,
        ]);

        $user = User::where('email', 'carlos.rodriguez@example.com')->first();

        $this->assertNotNull($user);
        $this->assertNotSame('Password123!', $user->password);
    }

    public function test_a_user_can_have_an_encarregado(): void
    {
        $encarregado = User::factory()->create([
            'name' => 'Ana Silva',
            'email' => 'ana.silva@example.com',
        ]);

        $user = User::factory()->create([
            'name' => 'Carlos Rodríguez',
            'email' => 'carlos.rodriguez@example.com',
            'encarregado_id' => $encarregado->id,
        ]);

        $this->assertNotNull($user->encarregado);
        $this->assertSame($encarregado->id, $user->encarregado->id);

        $this->assertTrue(
            $encarregado->subordinados->contains($user)
        );
    }

    public function test_encarregado_must_exist(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'carlos.validation@example.com',
            'password' => 'Password123!',
            'cpf' => '12345678902',
            'telefone_pessoal' => '62999999998',
            'encarregado_id' => 999999,
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['encarregado_id']);
    }

    public function test_name_is_required(): void
    {
        $response = $this->postJson('/users', [
            'email' => 'validation@example.com',
            'password' => 'Password123!',
            'cpf' => '12345678903',
            'telefone_pessoal' => '62999999997',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_email_is_optional(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'password' => 'Password123!',
            'cpf' => '12345678904',
            'telefone_pessoal' => '62999999996',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('email', null);
    }

    public function test_email_must_be_valid(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'email-invalido',
            'password' => 'Password123!',
            'cpf' => '12345678905',
            'telefone_pessoal' => '62999999995',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'existing@example.com',
            'password' => 'Password123!',
            'cpf' => '12345678906',
            'telefone_pessoal' => '62999999994',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_password_is_required(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'password.validation@example.com',
            'cpf' => '12345678907',
            'telefone_pessoal' => '62999999993',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_password_must_meet_security_requirements(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'password.security@example.com',
            'password' => 'password',
            'cpf' => '12345678908',
            'telefone_pessoal' => '62999999992',
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_a_secure_password_is_accepted(): void
    {
        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'password.secure@example.com',
            'password' => 'Klios@2026Secure',
            'cpf' => '12345678909',
            'telefone_pessoal' => '62999999991',
        ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('email', 'password.secure@example.com');
    }

    public function test_password_is_stored_hashed(): void
    {
        $password = 'Klios@2026Secure';

        $response = $this->postJson('/users', [
            'name' => 'Carlos Rodríguez',
            'email' => 'password.hash@example.com',
            'password' => $password,
            'cpf' => '12345678910',
            'telefone_pessoal' => '62999999990',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'password.hash@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertNotSame($password, $user->password);
    }

    public function test_route_for_create_user_returns_expected_message(): void
    {
        $response = $this->get('/users/create');
        $response
            ->assertStatus(200)
            ->assertSeeHtml('<title>Cadastrar usuário</title>');
    }
}