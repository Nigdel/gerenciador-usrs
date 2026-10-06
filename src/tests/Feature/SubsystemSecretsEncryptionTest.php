<?php

namespace Tests\Feature;

use App\Models\Subsystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Comprueba que api_config no queda en claro en la base de datos.
 *
 * Los secretos de los subsistemas (tokens, contraseñas de bind, credenciales
 * de Adagio) se guardan cifrados con el cast encrypted:array, así que el
 * texto de la columna es base64 y no JSON legible.
 */
class SubsystemSecretsEncryptionTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'glpi-token-super-secreto';

    private function crearSubsistema(): Subsystem
    {
        return Subsystem::create([
            'nombre' => 'GLPI Test',
            'slug' => 'glpi-test',
            'api_url' => 'https://glpi.test',
            'api_config' => ['token' => self::SECRETO, 'timeout' => 30],
            'activo' => true,
        ]);
    }

    /**
     * El valor guardado tiene que ser cifrado, no el JSON en claro.
     */
    public function test_el_secreto_no_se_guarda_en_claro(): void
    {
        $subsistema = $this->crearSubsistema();

        $guardado = DB::table('subsystems')->where('id', $subsistema->id)->value('api_config');

        $this->assertNotSame(self::SECRETO, $guardado);
        $this->assertStringNotContainsString(self::SECRETO, (string) $guardado);
        $this->assertStringNotContainsString('"token"', (string) $guardado);

        // Base64 y no JSON legible: esa es la señal de que el cast está aplicado.
        $this->assertNull(json_decode((string) $guardado, true));
        $this->assertNotNull(json_decode(Crypt::decryptString((string) $guardado), true));
    }

    /**
     * El modelo sigue devolviendo el array tal cual se guardó.
     */
    public function test_el_modelo_sigue_leyendo_la_configuracion(): void
    {
        $subsistema = $this->crearSubsistema();

        $this->assertSame(
            ['token' => self::SECRETO, 'timeout' => 30],
            $subsistema->fresh()->api_config
        );

        $this->assertSame('https://glpi.test', $subsistema->fresh()->access_url);
    }

    /**
     * Actualizar el subsistema no deja el valor en claro, ni siquiera
     * reescribiendo campos que no son api_config.
     */
    public function test_actualizar_el_subsistema_no_descifra_nada(): void
    {
        $subsistema = $this->crearSubsistema();

        $subsistema->update(['nombre' => 'GLPI Renombrado']);

        $guardado = DB::table('subsystems')->where('id', $subsistema->id)->value('api_config');

        $this->assertStringNotContainsString(self::SECRETO, (string) $guardado);
        $this->assertSame(self::SECRETO, $subsistema->fresh()->api_config['token']);
    }

    /**
     * api_config está en $hidden: no puede viajar en la serialización.
     */
    public function test_api_config_no_aparece_al_serializar(): void
    {
        $subsistema = $this->crearSubsistema();

        $this->assertArrayNotHasKey('api_config', $subsistema->toArray());
        $this->assertStringNotContainsString(self::SECRETO, $subsistema->toJson());

        // La ficha del subsistema tampoco lo muestra: solo lo ve el
        // formulario de edición, que es donde se escribe.
        $html = $this->actingAs(User::factory()->admin()->create())
            ->get(route('subsystems.show', $subsistema))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(self::SECRETO, $html);
    }

    /**
     * Una fila con la configuración en claro (p. ej. creada antes de la
     * migración) sigue siendo legible para quien ya la tiene.
     */
    public function test_una_configuracion_vacia_se_guarda_como_nulo(): void
    {
        $subsistema = Subsystem::create([
            'nombre' => 'Slack Test',
            'slug' => 'slack-test',
            'api_config' => null,
        ]);

        $this->assertNull($subsistema->fresh()->api_config);
        $this->assertNull(DB::table('subsystems')->where('id', $subsistema->id)->value('api_config'));
    }
}
