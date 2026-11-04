<?php

namespace Tests\Feature;

use App\Contracts\IdentityProviderInterface;
use App\Contracts\SubsystemServiceInterface;
use App\DTO\SubsystemOperationResult;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Import\RemoteAccount;
use App\Services\Import\RemoteAccountEnumerator;
use App\Services\Import\UserImportService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La importación masiva se prueba con enumeradores y proveedor de identidad
 * falsos: lo que importa no es la API de GLPI/EntraID sino la correlación
 * por CPF/login/email, el dedupe y lo que queda en los reportes.
 */
class UserImportRemoteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> cpf => datos */
    private array $adagio = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);

        Subsystem::unguard();
        Subsystem::create(['nombre' => 'GLPI', 'slug' => 'glpi', 'activo' => true]);
        Subsystem::create(['nombre' => 'EntraID', 'slug' => 'entraid', 'activo' => true]);
        Subsystem::create(['nombre' => 'Samba AD', 'slug' => 'sambaad', 'activo' => true]);
        Subsystem::create(['nombre' => 'Chatwoot', 'slug' => 'chatwoot', 'activo' => true, 'api_config' => ['accounts' => ['klios' => 1]]]);
        Subsystem::create(['nombre' => 'Adagio', 'slug' => 'adagio', 'activo' => true, 'es_proveedor_identidad' => true]);
        Subsystem::reguard();

        config([
            'subsystems.drivers.adagio' => FakeAdagioForImport::class,
            'subsystems.empresas' => ['Klios', 'Federal'],
        ]);

        $this->app->singleton(FakeAdagioForImport::class, fn () => new FakeAdagioForImport($this->adagio));
    }

    private function importador(array $cuentas): UserImportService
    {
        $fakes = [];

        foreach ($cuentas as $slug => $lista) {
            $fakes[$slug] = new class($slug, $lista) implements RemoteAccountEnumerator
            {
                public function __construct(
                    private readonly string $slug,
                    /** @var list<RemoteAccount> */
                    private readonly array $cuentas,
                ) {}

                public function slug(): string
                {
                    return $this->slug;
                }

                public function enumerar(Subsystem $subsystem): array
                {
                    return $this->cuentas;
                }
            };
        }

        return app(UserImportService::class)->conEnumeradores($fakes);
    }

    // -----------------------------------------------------------------

    public function test_importa_un_gestor_por_usuario_activo_de_glpi_y_asocia_las_cuentas(): void
    {
        $this->adagio = ['12345678901' => ['nome' => 'Maria Souza', 'email' => 'maria@klios.com.br', 'documento' => '123.456.789-01']];
        $this->app->singleton(FakeAdagioForImport::class, fn () => new FakeAdagioForImport($this->adagio));

        $reporte = $this->importador([
            'glpi' => [
                new RemoteAccount('glpi', '10', login: 'msouza', cpf: '12345678901', estado: 'activo'),
                new RemoteAccount('glpi', '20', login: 'sin cpf', estado: 'activo'),
            ],
            'entraid' => [
                new RemoteAccount('entraid', 'abc', login: 'msouza', cpf: '12345678901', estado: 'activo', meta: ['empresa' => 'Klios']),
                new RemoteAccount('entraid', 'bbc', login: 'otro', estado: 'deshabilitado'),
            ],
            'sambaad' => [
                new RemoteAccount('sambaad', 'msouza', login: 'msouza', cpf: '12345678901', estado: 'suspendido'),
            ],
            'chatwoot' => [
                new RemoteAccount('chatwoot', '55', email: 'maria@klios.com.br', estado: 'activo', meta: ['empresa' => 'klios']),
            ],
        ])->importar();

        $this->assertSame(1, $reporte['resumen']['gestores_creados']);

        $gestor = GestorUser::where('cpf', '12345678901')->firstOrFail();
        $this->assertSame('msouza', $gestor->usuario);
        $this->assertSame('Klios', $gestor->empresa);

        $slugs = $gestor->subsystemAccounts()->with('subsystem')->get()
            ->map(fn ($account) => $account->subsystem->slug)->sort()->values()->all();
        $this->assertSame(['chatwoot', 'entraid', 'glpi', 'sambaad'], $slugs);

        // El estado remoto se mapea: samba suspendida no se importa como activa.
        $samba = UserSubsystemAccount::where('gestor_user_id', $gestor->id)
            ->whereHas('subsystem', fn ($q) => $q->where('slug', 'sambaad'))->first();
        $this->assertSame('suspendido', $samba->estado->value);

        // EntraID sí quedó activa
        $entra = UserSubsystemAccount::where('gestor_user_id', $gestor->id)
            ->whereHas('subsystem', fn ($q) => $q->where('slug', 'entraid'))->first();
        $this->assertSame('activo', $entra->estado->value);

        // El usuario GLPI sin CPF no se importó y queda anotado para revisión.
        $this->assertSame(1, $reporte['resumen']['glpi_sin_cpf']);
        $this->assertCount(1, $reporte['conflictos']);
        $this->assertSame('glpi_sin_cpf', $reporte['conflictos'][0]['tipo']);

        // La cuenta "huérfana" de EntraID (sin correlación con nadie) queda en su CSV-report.
        $this->assertCount(2, $reporte['huerfanos']);
        $this->assertSame('bbc', $reporte['huerfanos'][1]['external_id']);
    }

    public function test_reimportar_no_duplica(): void
    {
        $this->adagio = ['11122333444' => ['nome' => 'Ana Cruz', 'email' => 'acruz@klios.com.br']];
        $this->app->singleton(FakeAdagioForImport::class, fn () => new FakeAdagioForImport($this->adagio));

        $importador = $this->importador([
            'glpi' => [new RemoteAccount('glpi', '7', login: 'acruz', cpf: '11122333444', estado: 'activo')],
            'entraid' => [new RemoteAccount('entraid', 'x7', login: 'acruz', cpf: '11122333444', estado: 'activo', meta: ['empresa' => 'Klios'])],
        ]);

        $primera = $importador->importar();
        $segunda = $importador->importar();

        $this->assertSame(1, $primera['resumen']['gestores_creados']);
        $this->assertSame(0, $segunda['resumen']['gestores_creados']);
        $this->assertSame(1, $segunda['resumen']['gestores_actualizados']);
        $this->assertSame(0, $segunda['resumen']['cuentas_creadas']);
        $this->assertSame(2, $segunda['resumen']['cuentas_actualizadas']);

        $this->assertSame(1, GestorUser::count());
        $this->assertSame(2, UserSubsystemAccount::count());
    }

    public function test_rescata_el_cpf_cuando_otra_plataforma_lo_tiene(): void
    {
        $this->adagio = ['99988877766' => ['nome' => 'Leo Lima', 'email' => 'llima@klios.com.br']];
        $this->app->singleton(FakeAdagioForImport::class, fn () => new FakeAdagioForImport($this->adagio));

        $reporte = $this->importador([
            // GLPI sin CPF, pero el mismo login existe en Samba con CPF.
            'glpi' => [new RemoteAccount('glpi', '3', login: 'llima', estado: 'activo')],
            'sambaad' => [new RemoteAccount('sambaad', 'llima', login: 'llima', cpf: '99988877766', estado: 'activo', meta: ['empresa' => 'Klios'])],
        ])->importar();

        $this->assertSame(1, $reporte['resumen']['gestores_creados']);
        $this->assertSame(0, $reporte['resumen']['glpi_sin_cpf']);
        $this->assertSame('Leo Lima', GestorUser::where('cpf', '99988877766')->firstOrFail()->nombre_completo);

        // GLPI y Samba fueron consumidas: no hay huérfanos.
        $this->assertSame([], $reporte['huerfanos']);
    }

    public function test_dry_run_no_escribe(): void
    {
        $this->adagio = ['55566677788' => ['nome' => 'Rita Rao', 'email' => 'rrao@klios.com.br']];
        $this->app->singleton(FakeAdagioForImport::class, fn () => new FakeAdagioForImport($this->adagio));

        $reporte = $this->importador([
            'glpi' => [new RemoteAccount('glpi', '4', login: 'rrao', cpf: '55566677788', estado: 'activo')],
            'entraid' => [new RemoteAccount('entraid', 'e4', login: 'rrao', cpf: '55566677788', estado: 'activo')],
        ])->importar(['dry_run' => true]);

        $this->assertSame(1, $reporte['resumen']['gestores_creados']);
        $this->assertSame(2, $reporte['resumen']['cuentas_creadas']);
        $this->assertSame(0, GestorUser::count());
        $this->assertSame(0, UserSubsystemAccount::count());
    }
}

/**
 * Proveedor de identidad falso: responde por CPF sin tocar la API real de
 * Adagio. Los CPF conocidos viven en memoria.
 */
class FakeAdagioForImport implements IdentityProviderInterface, SubsystemServiceInterface
{
    /** @param  array<string, array<string, mixed>>  $cuentas */
    public function __construct(private readonly array $cuentas) {}

    public function findByCpf(string $cpf): ?array
    {
        return $this->cuentas[$cpf] ?? null;
    }

    public function existsByEmail(string $email): bool
    {
        return collect($this->cuentas)->contains(fn ($cuenta) => $cuenta['email'] === $email);
    }

    public function createUser(array $userData, Subsystem $subsystem): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no crea usuarios');
    }

    public function suspendUser(UserSubsystemAccount $account, array $suspensionData): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no suspende usuarios');
    }

    public function reactivateUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no reactiva usuarios');
    }

    public function disableUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no deshabilita usuarios');
    }

    public function supportsDeleteUser(): bool
    {
        return true;
    }

    public function deleteUser(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no borra usuarios');
    }

    public function getUserStatus(UserSubsystemAccount $account): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no consulta estado');
    }

    public function resetPassword(UserSubsystemAccount $account, string $newPassword): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no resetea contraseñas');
    }

    public function supportsUpdateUser(): bool
    {
        return true;
    }

    public function updateUser(UserSubsystemAccount $account, array $userData): SubsystemOperationResult
    {
        return SubsystemOperationResult::fail('El fake no actualiza usuarios');
    }

    public function testConnection(Subsystem $subsystem): SubsystemOperationResult
    {
        return SubsystemOperationResult::ok();
    }
}
