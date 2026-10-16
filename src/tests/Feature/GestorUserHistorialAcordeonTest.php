<?php

namespace Tests\Feature;

use App\Models\AccountStateLog;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acordeón del histórico: una entrada abierta a la vez.
 *
 * Que solo haya una abierta lo decide Alpine, así que lo que se puede fijar
 * desde el servidor es lo que hace que eso sea cierto: que todas las entradas
 * compartan **una** variable de estado y no una cada una, que el `x-show` de
 * cada cuerpo compare contra esa misma variable, y que el cableado ARIA apunte
 * a un elemento que exista. Un `x-data` por entrada —el error fácil de
 * cometer— seguiría marcando `aria-expanded="false"` al cargar y pasaría estos
 * tests igual, así que el primer caso es el que importa.
 */
class GestorUserHistorialAcordeonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->operador()->create());
    }

    private function usuarioConHistorial(int $entradas = 3): GestorUser
    {
        $usuario = GestorUser::create([
            'nombre_completo' => 'Ana Silva',
            'cpf' => '12345678900',
            'password_general' => 'Password123!',
            'usuario' => 'ana.silva',
            'empresa' => 'klios',
        ]);

        $subsistema = Subsystem::create([
            'nombre' => 'GLPI',
            'slug' => 'glpi',
            'api_url' => 'https://glpi.test',
        ]);

        for ($i = 1; $i <= $entradas; $i++) {
            AccountStateLog::create([
                'gestor_user_id' => $usuario->id,
                'subsystem_id' => $subsistema->id,
                'evento' => AccountStateLog::UPDATED,
                'cambios' => ['estado' => ['desde' => 'activo', 'hasta' => 'deshabilitado']],
                'actor_nombre' => 'Super Admin',
                'origen' => 'web',
                'descripcion' => "Entrada de histórico {$i}",
            ]);
        }

        return $usuario;
    }

    /**
     * @return array{0: DOMXPath, 1: DOMDocument}
     */
    private function fichaComoDom(GestorUser $usuario): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($this->get(route('gestor-users.show', $usuario))->assertOk()->getContent());

        return [new DOMXPath($dom), $dom];
    }

    public function test_todas_las_entradas_comparten_una_sola_variable_de_estado(): void
    {
        [$xpath] = $this->fichaComoDom($this->usuarioConHistorial());

        // El <ol> del histórico es el contenedor del acordeón. Un x-data por
        // entrada sería un acordeón que abre todas a la vez.
        $contenedores = $xpath->query('//ol[@x-data]');

        $this->assertSame(
            1,
            $contenedores->length,
            'El acordeón debe declararse una sola vez, en el contenedor del listado.',
        );

        $this->assertStringContainsString(
            'abierto',
            $contenedores->item(0)->attributes->getNamedItem('x-data')->nodeValue,
        );
    }

    public function test_los_cuerpos_se_muestran_comparando_contra_el_estado_compartido(): void
    {
        $usuario = $this->usuarioConHistorial();
        [$xpath] = $this->fichaComoDom($usuario);

        $cuerpos = $xpath->query('//div[@x-show][@id]');

        $this->assertSame(
            $usuario->historial()->count(),
            $cuerpos->length,
            'Cada entrada debe tener su propio cuerpo plegable.',
        );

        foreach ($cuerpos as $cuerpo) {
            $id = $cuerpo->getAttribute('id');
            $entradaId = str_replace('historial-', '', $id);

            $this->assertStringContainsString(
                sprintf('abierto === %s', $entradaId),
                $cuerpo->getAttribute('x-show'),
                'El cuerpo debe abrirse comparando contra el estado compartido.',
            );
        }
    }

    public function test_el_cableado_aria_apunta_a_elementos_que_existen(): void
    {
        [$xpath] = $this->fichaComoDom($this->usuarioConHistorial());

        $botones = $xpath->query('//button[@aria-controls]');

        $this->assertSame(3, $botones->length);

        foreach ($botones as $boton) {
            /** @var DOMElement $boton */
            $controlado = $boton->getAttribute('aria-controls');

            $this->assertSame(
                1,
                $xpath->query(sprintf('//*[@id="%s"]', $controlado))->length,
                "aria-controls=\"{$controlado}\" debe apuntar a un elemento existente.",
            );

            // Se renderiza plegado: nada debe quedar abierto sin que el operador
            // lo abra.
            $this->assertSame('false', $boton->getAttribute('aria-expanded'));
        }
    }

    public function test_el_cambio_de_atributos_queda_dentro_del_cuerpo_plegable(): void
    {
        $usuario = $this->usuarioConHistorial(entradas: 1);
        [$xpath] = $this->fichaComoDom($usuario);

        $cuerpo = $xpath->query('//div[@x-show][@id]')->item(0);

        $this->assertNotNull($cuerpo);
        $this->assertSame(
            1,
            $xpath->query('.//dl', $cuerpo)->length,
            'El detalle de cambios debe vivir dentro del cuerpo, no en el encabezado.',
        );
    }
}
