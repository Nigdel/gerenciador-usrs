<?php

namespace App\Services\Import;

use App\Enums\GestorUserStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiService;
use App\Services\SubsystemServiceRegistry;
use App\Services\TemporaryPasswordGenerator;
use Throwable;

/**
 * Importación masiva de cuentas existentes de los subsistemas remotos.
 *
 * Funcionamiento:
 *
 *  1. Cada RemoteAccountEnumerator lista las cuentas que hay realmente en
 *     cada subsistema (glpi, entraid, sambaad, chatwoot), sin escribir nada.
 *  2. GLPI es la fuente de existencia: por cada usuario ACTIVO en GLPI se
 *     crea un GestorUser. Adagio (proveedor de identidad) es la fuente de
 *     datos: por CPF devuelve nombre/usuario/email autoritativos.
 *  3. El CPF es la clave de correlación primaria (comment en GLPI, documento
 *     en Adagio, employeeId en EntraID, employeeNumber en Samba AD); login y
 *     email son fallbacks. Chatwoot solo puede correlacionarse por email.
 *  4. Las cuentas se persisten con updateOrCreate sobre la clave
 *     UNIQUE(gestor_user_id, subsystem_id): correr el import dos veces no
 *     duplica nada.
 *  5. Todo lo que no pudo anclarse (login sin CPF en GLPI, cuentas remotas
 *     huérfanas, CPFs duplicados) sale en conflictos, sin inventarse.
 */
class UserImportService
{
    /** @var array<string, RemoteAccountEnumerator> slug => enumerador */
    private array $enumeradores;

    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly TemporaryPasswordGenerator $passwordGenerator,
    ) {
        $this->enumeradores = $this->enumeradoresPorDefecto();
    }

    /**
     * Punto de sutura para los tests: sustituye los enumeradores y el
     * proveedor de identidad reales por fakes.
     *
     * @param  array<string, RemoteAccountEnumerator>  $enumeradores
     */
    public function conEnumeradores(array $enumeradores): self
    {
        $this->enumeradores = $enumeradores;

        return $this;
    }

    /**
     * @return array<string, RemoteAccountEnumerator>
     */
    private function enumeradoresPorDefecto(): array
    {
        return [
            'glpi' => app(GlpiRemoteAccountEnumerator::class),
            'adagio' => app(AdagioRemoteAccountEnumerator::class),
            'entraid' => app(EntraIdRemoteAccountEnumerator::class),
            'sambaad' => app(SambaAdRemoteAccountEnumerator::class),
            'chatwoot' => app(ChatwootRemoteAccountEnumerator::class),
            'email' => app(EmailRemoteAccountEnumerator::class),
            'slack' => app(SlackRemoteAccountEnumerator::class),
        ];
    }

    /**
     * Ejecuta la importación y devuelve los reportes.
     *
     * @param  array{dry_run?: bool, subsistemas?: ?array<int, string>, incluir_inactivos_glpi?: bool}  $opciones
     * @return array{resumen: array<string, mixed>, conflictos: list<array<string, string>>, faltantes: list<array<string, string>>, huerfanos: list<array<string, ?string>>}
     */
    public function importar(array $opciones = []): array
    {
        $opciones = [
            'dry_run' => (bool) ($opciones['dry_run'] ?? false),
            'incluir_inactivos_glpi' => (bool) ($opciones['incluir_inactivos_glpi'] ?? false),
            'subsistemas' => $opciones['subsistemas'] ?? null,
        ];

        $enums = $this->enumeradores;

        if ($opciones['subsistemas'] !== null && $opciones['subsistemas'] !== []) {
            $enums = array_intersect_key($enums, array_fill_keys($opciones['subsistemas'], true));
        }

        $reporte = [
            'resumen' => [
                'remotas_por_subsistema' => [],
                'glpi_recorridos' => 0,
                'glpi_sin_cpf' => 0,
                'cpf_duplicado' => 0,
                'gestores_creados' => 0,
                'gestores_actualizados' => 0,
                'cuentas_creadas' => 0,
                'cuentas_actualizadas' => 0,
            ],
            'conflictos' => [],
            'faltantes' => [],
            'huerfanos' => [],
        ];

        // Las cuentas remotas se enumeran una sola vez: la importación es
        // coherente contra un único snapshot de cada plataforma.
        $enumeradas = [];
        $erroresEnumeracion = false;

        foreach ($enums as $slug => $enumerator) {
            $subsystem = Subsystem::activos()->where('slug', $slug)->first();

            if ($subsystem === null) {
                $this->conflicto($reporte['conflictos'], 'subsistema', $slug, 'No existe un subsistema activo con ese slug');
                $erroresEnumeracion = true;

                continue;
            }

            try {
                $enumeradas[$slug] = $enumerator->enumerar($subsystem);
                $reporte['resumen']['remotas_por_subsistema'][$slug] = count($enumeradas[$slug]);
            } catch (Throwable $e) {
                report($e);
                $this->conflicto($reporte['conflictos'], 'enumeracion', $slug, 'No se pudo enumerar: '.$e->getMessage());
                $erroresEnumeracion = true;
            }
        }

        $indices = IndicesImportacion::construir($this->subsistemas($enums, array_keys($enumeradas)), $enumeradas);

        if ($erroresEnumeracion || ! isset($enumeradas['glpi'])) {
            $this->conflicto($reporte['conflictos'], 'glpi', '-', 'Imposible importar: GLPI (fuente de existencia) no se pudo enumerar o falló otra plataforma; se recomienda resolverlo y reintentar');

            return $reporte;
        }

        $procesadas = $this->importarDesdeGlpi($indices, $opciones, $reporte);

        $this->reportarHuerfanos($indices, $procesadas, $reporte);

        return $reporte;
    }

    /**
     * Backfill de CPF para los usuarios activos de GLPI que todavía no lo
     * tienen: se enumeran sus cuentas, se buscan en Adagio por email (el
     * username de GLPI se convierte en email en cada dominio conocido) y se
     * escribe el CPF encontrado en el comentario del usuario de GLPI para que
     * el siguiente import pueda correlacionarlo.
     *
     * @param  array{dry_run?: bool}  $opciones
     * @return array{procesados: int, rellenados: list<array<string, string>>, fallos: list<array<string, string>>}
     */
    public function rellenarCpfs(array $opciones = []): array
    {
        $dryRun = (bool) ($opciones['dry_run'] ?? false);
        $reporte = ['procesados' => 0, 'rellenados' => [], 'fallos' => []];

        $subsystem = Subsystem::activos()->where('slug', 'glpi')->first();

        if ($subsystem === null) {
            return $reporte;
        }

        $enumerador = $this->enumeradores['glpi'];

        foreach ($enumerador->enumerar($subsystem) as $cuenta) {
            if ($cuenta->estado !== 'activo') {
                continue;
            }

            if ($cuenta->cpfLimpio() !== null) {
                continue;
            }

            $reporte['procesados']++;

            $cpf = null;
            $email = null;

            foreach ($this->emailsDeUsuario($cuenta->login) as $candidato) {
                $datos = $this->registry->resolveIdentityProvider()->findByEmail($candidato);

                if ($datos === null) {
                    continue;
                }

                $email = $candidato;
                $cpf = (string) ($datos['cpf'] ?? '');

                break;
            }

            if ($cpf === null || $cpf === '') {
                $cpf = (string) ($datos['documento'] ?? '');
            }

            $cpf = preg_replace('/\D/', '', (string) $cpf);

            if ($cpf === null || $cpf === '') {
                $reporte['fallos'][] = [
                    'login' => (string) $cuenta->login,
                    'mensaje' => 'No se encontró el usuario en Adagio por ninguno de los emails derivados del username',
                ];

                continue;
            }

            if ($dryRun) {
                $reporte['rellenados'][] = ['login' => (string) $cuenta->login, 'email' => $email, 'cpf' => $cpf];

                continue;
            }

            $resultado = app(GlpiService::class)->asignarCpf($subsystem, $cuenta->externalAccountId, $cpf);

            if (! $resultado->success) {
                $reporte['fallos'][] = [
                    'login' => (string) $cuenta->login,
                    'email' => $email,
                    'mensaje' => (string) ($resultado->mensaje ?? 'No se pudo registrar el CPF en GLPI'),
                ];

                continue;
            }

            $reporte['rellenados'][] = ['login' => (string) $cuenta->login, 'email' => $email, 'cpf' => $cpf];
        }

        return $reporte;
    }

    /**
     * Emails candidatos para buscar a un usuario de GLPI sin CPF: el username
     * se convierte en email en cada dominio de Adagio que se conoce.
     *
     * @return list<string>
     */
    private function emailsDeUsuario(?string $login): array
    {
        $usuario = strtolower(trim((string) $login));
        $dominios = ['klios.com.br', 'federalst.com.br'];

        $subsystem = Subsystem::activos()->where('slug', 'glpi')->first();

        $dominioConfig = strtolower(trim((string) ($subsystem?->api_config['dominio'] ?? '')));

        if ($dominioConfig !== '') {
            $dominios[] = $dominioConfig;
        }

        $emails = [];

        foreach ($dominios as $dominio) {
            if ($dominio === '') {
                continue;
            }

            $email = $usuario.'@'.$dominio;

            if (! str_contains($email, '@') || in_array($email, $emails, true)) {
                continue;
            }

            $emails[] = $email;
        }

        return $emails;
    }

    /**
     * Subsystem solo de los slugs enumerados que existan en la tabla.
     *
     * @param  array<string, RemoteAccountEnumerator>  $enums
     * @param  list<string>  $slugsEnumerados
     * @return array<string, Subsystem>
     */
    private function subsistemas(array $enums, array $slugsEnumerados): array
    {
        return Subsystem::activos()
            ->whereIn('slug', array_keys($enums))
            ->get()
            ->filter(fn (Subsystem $s): bool => in_array($s->slug, $slugsEnumerados, true))
            ->keyBy(fn (Subsystem $s): string => $s->slug)
            ->all();
    }

    // -----------------------------------------------------------------
    // Importación por usuario de GLPI
    // -----------------------------------------------------------------

    /**
     * @param  array{dry_run: bool, incluir_inactivos_glpi: bool}  $opciones
     * @param  array{resumen: array<string, mixed>, conflictos: list<array<string, string>>, faltantes: list<array<string, string>>, huerfanos: list<array<string, ?string>>}  $reporte
     * @return list<string> fingerprints de las cuentas remotas consumidas
     */
    private function importarDesdeGlpi(IndicesImportacion $indices, array $opciones, array &$reporte): array
    {
        $consumidas = [];
        $cpfsVistos = [];

        foreach ($indices->remotas('glpi') as $cuentaGlpi) {
            if ($cuentaGlpi->estado === 'borrado') {
                continue;
            }

            if ($cuentaGlpi->estado === 'deshabilitado' && ! $opciones['incluir_inactivos_glpi']) {
                continue;
            }

            $reporte['resumen']['glpi_recorridos']++;

            $cpf = $cuentaGlpi->cpfLimpio();
            $cpfRescatado = false;

            if ($cpf === null) {
                // Sin CPF en GLPI se intenta rescatarlo: si otra plataforma
                // dice tener este login o este email con CPF conocido, es la
                // misma persona salvo causa extraordinaria.
                $cpf = $indices->rescatarCpf($cuentaGlpi);
                $cpfRescatado = $cpf !== null;

                if ($cpf === null) {
                    $reporte['resumen']['glpi_sin_cpf']++;
                    $this->conflicto($reporte['conflictos'], 'glpi_sin_cpf', (string) $cuentaGlpi->login, 'Usuario de GLPI sin CPF y sin correlación posible: revisar manualmente');

                    continue;
                }
            }

            if (isset($cpfsVistos[$cpf])) {
                $reporte['resumen']['cpf_duplicado']++;
                $this->conflicto($reporte['conflictos'], 'cpf_duplicado', (string) $cuentaGlpi->login, "El CPF {$cpf} ya apareció en GLPI con el usuario '{$cpfsVistos[$cpf]}': se requiere revisión antes de importarlo");

                continue;
            }

            $cpfsVistos[$cpf] = (string) $cuentaGlpi->login;

            $datosAdagio = $this->datosDeAdagio($cpf, $reporte);

            if ($datosAdagio === null) {
                continue;
            }

            $datos = $this->resolverDatos($cuentaGlpi, $datosAdagio, $cpf, $cpfRescatado, $indices);

            $gestor = $this->guardarGestor($datos, $opciones['dry_run'], $reporte);

            if ($gestor === null) {
                continue;
            }

            $consumidas[] = $cuentaGlpi->fingerprint();

            foreach ($this->asociarCuentas($gestor, $datos, $cuentaGlpi, $indices, $cpf, $opciones['dry_run'], $reporte) as $fingerprint) {
                $consumidas[] = $fingerprint;
            }
        }

        return array_values(array_unique($consumidas));
    }

    /**
     * Devuelve null si Adagio no confirma al CPF: sin el proveedor de
     * identidad no hay datos autoritativos, así que el usuario se deja
     * anotado y no se importa.
     *
     * @return array{nombre_completo: ?string, email_personal: ?string, usuario: ?string, external_account_id_adagio: ?string}|null
     */
    private function datosDeAdagio(string $cpf, array &$reporte): ?array
    {
        $servicio = null;

        try {
            $servicio = $this->registry->resolveIdentityProvider();
        } catch (Throwable $e) {
            report($e);
            $this->conflicto($reporte['conflictos'], 'adagio', $cpf, 'No hay proveedor de identidad activo: '.$e->getMessage());

            return null;
        }

        try {
            $datos = $servicio->findByCpf($cpf);
        } catch (Throwable $e) {
            report($e);
            $this->conflicto($reporte['conflictos'], 'adagio', $cpf, 'Adagio no respondió para este CPF: '.$e->getMessage());

            return null;
        }

        if ($datos === null) {
            $this->conflicto($reporte['conflictos'], 'adagio', $cpf, 'El CPF no existe en Adagio: darlo de alta primero en el proveedor de identidad');

            return null;
        }

        return [
            'nombre_completo' => $datos['nombre_completo'] ?? $datos['nome'] ?? null,
            'email_personal' => $datos['email_personal'] ?? $datos['email'] ?? null,
            'usuario' => $datos['usuario'] ?? null,
            'external_account_id_adagio' => $datos['external_account_id'] ?? $datos['externalAccountId'] ?? null,
        ];
    }

    /**
     * Cruza lo que dice Adagio (fuente de datos) con lo que dice GLPI
     * (fuente de existencia).
     *
     * @param  array{nombre_completo: ?string, email_personal: ?string, usuario: ?string, external_account_id_adagio: ?string}  $datosAdagio
     * @return array{nombre_completo: string, email_personal: ?string, usuario: string, cpf: string, external_account_id_adagio: ?string}
     */
    private function resolverDatos(RemoteAccount $cuentaGlpi, array $datosAdagio, string $cpf, bool $cpfRescatado, IndicesImportacion $indices): array
    {
        return [
            'nombre_completo' => trim((string) ($datosAdagio['nombre_completo'] ?? $cuentaGlpi->nombreCompleto ?? '')),
            'email_personal' => $datosAdagio['email_personal'] ?? $cuentaGlpi->email,
            'usuario' => (string) ($datosAdagio['usuario'] ?? $cuentaGlpi->login ?? ''),
            'cpf' => $cpf,
            'external_account_id_adagio' => $datosAdagio['external_account_id_adagio'] ?? $datosAdagio['external_account_id'] ?? null,
            'empresa' => $this->resolverEmpresa(
                $cpf,
                (string) ($datosAdagio['usuario'] ?? $cuentaGlpi->login ?? ''),
                (string) ($datosAdagio['email_personal'] ?? ''),
                $indices,
            ),
        ];
    }

    /**
     * La empresa es la que sugieren las plataformas ya vigentes: EntraID y
     * Chatwoot la llevan como identificador de cuenta, y el UPN de Samba
     * encuadre contra los dominios declarados de EntraID.
     */
    private function resolverEmpresa(string $cpf, string $usuario, string $emailPersonal, IndicesImportacion $indices): ?string
    {
        $mapaEmpresas = array_combine(
            array_map(static fn (string $empresa): string => strtolower($empresa), config('subsystems.empresas', [])),
            config('subsystems.empresas', []),
        );

        // 1) EntraID: sus tenants llevan clave = empresa.
        foreach ($indices->remotas('entraid') as $cuenta) {
            if ($cuenta->cpfLimpio() === $cpf) {
                $resuelta = $this->empresaDeConfiguracion($cuenta->meta['empresa'] ?? null, $mapaEmpresas);

                if ($resuelta !== null) {
                    return $resuelta;
                }
            }
        }

        // 2) Chatwoot: la cuenta (account) también lleva el identificador.
        if (isset($indices->subsistemas()['chatwoot'])) {
            $chatwoot = $indices->buscar('chatwoot', $cpf, $usuario, []);

            if ($chatwoot !== null) {
                $resuelta = $this->empresaDeConfiguracion($chatwoot->meta['empresa'] ?? null, $mapaEmpresas);

                if ($resuelta !== null) {
                    return $resuelta;
                }
            }
        }

        // 3) Samba: el UPN se encuadra contra los dominios declarados, y el
        //    propio registro puede llevar el identificador de cuenta.
        foreach ($indices->remotas('sambaad') as $cuenta) {
            if ($cuenta->cpfLimpio() !== $cpf) {
                continue;
            }

            $resuelta = $this->empresaDeConfiguracion($cuenta->meta['empresa'] ?? null, $mapaEmpresas);

            if ($resuelta === null) {
                $upn = $cuenta->meta['upn'] ?? null;

                if (is_string($upn) && str_contains($upn, '@')) {
                    $empresa = $indices->empresaPorDominio(substr($upn, (int) strrpos($upn, '@') + 1));

                    if ($empresa !== null) {
                        $resuelta = $this->empresaDeConfiguracion($empresa, $mapaEmpresas);
                    }
                }
            }

            if ($resuelta !== null) {
                return $resuelta;
            }
        }

        // 4) Email: el dominio del correo personal (fuente Adagio) encaja
        //    contra los dominios declarados por empresa en los indices.
        if (filled($emailPersonal) && str_contains(strtolower($emailPersonal), '@')) {
            $empresa = $indices->empresaPorDominio((string) substr(strtolower($emailPersonal), (int) strrpos(strtolower($emailPersonal), '@') + 1));

            if ($empresa !== null) {
                $resuelta = $this->empresaDeConfiguracion($empresa, $mapaEmpresas);

                if ($resuelta !== null) {
                    return $resuelta;
                }
            }
        }

        // La columna "empresa" es NOT NULL en la base de datos: sin ninguna
        // fuente que la atribuya se usa la primera empresa configurada como
        // valor por defecto, para que el alta del gestor no falle en el
        // constraint en vez de dejar al usuario sin registrar.
        return $mapaEmpresas[array_key_first($mapaEmpresas)] ?? null;
    }

    /**
     * Normaliza el identificador de empresa de la plataforma (ej. 'klios')
     * contra las empresas declaradas en config ('Klios', 'Federal'), que son
     * el literal que la tabla gestor_users guarda. Null si no encuadra.
     *
     * @param  array<string, string>  $mapaEmpresas  clave en minúsculas => literal de config
     */
    private function empresaDeConfiguracion(mixed $identificador, array $mapaEmpresas): ?string
    {
        if (! is_string($identificador) || $identificador === '') {
            return null;
        }

        $clave = strtolower($identificador);

        return $mapaEmpresas[$clave] ?? null;
    }

    private function guardarGestor(array $datos, bool $dryRun, array &$reporte): ?GestorUser
    {
        if ($dryRun) {
            $existente = GestorUser::where('cpf', $datos['cpf'])->first();
            $reporte['resumen'][$existente === null ? 'gestores_creados' : 'gestores_actualizados']++;

            return $existente ?? new GestorUser([
                'cpf' => $datos['cpf'],
                'usuario' => $datos['usuario'],
                'email_personal' => $datos['email_personal'],
                'nombre_completo' => $datos['nombre_completo'],
                'estado' => GestorUserStatus::Activo->value,
            ]);
        }

        try {
            $existente = GestorUser::where('cpf', $datos['cpf'])->first();

            if ($existente !== null) {
                // La contraseña general ya existe: no se rota en cada
                // reimportación (igual que el provisioning no la toca al
                // reutilizar al CPF). Solo se armonizan los datos.
                $existente->fill([
                    'nombre_completo' => $datos['nombre_completo'],
                    'email_personal' => $datos['email_personal'],
                    'usuario' => $datos['usuario'],
                    'empresa' => $datos['empresa'],
                    'estado' => GestorUserStatus::Activo->value,
                ])->save();

                $reporte['resumen']['gestores_actualizados']++;

                return $existente;
            }

            $gestor = GestorUser::create([
                'cpf' => $datos['cpf'],
                'nombre_completo' => $datos['nombre_completo'],
                'email_personal' => $datos['email_personal'],
                'usuario' => $datos['usuario'],
                // La general se genera y queda hasheada por el hook saving()
                // del modelo (igual que en el provisioning local).
                'password_general' => $this->passwordGenerator->generar(),
                'empresa' => $datos['empresa'],
                'estado' => GestorUserStatus::Activo->value,
            ]);

            $reporte['resumen']['gestores_creados']++;

            return $gestor;
        } catch (Throwable $e) {
            // El conflicto más probable: usuario UNIQUE ya tomado por otro
            // gestor (un CPF distinto con el login del usuario). Con el CPF
            // igual el updateOrCreate lo habría reutilizado sin tocar esto.
            report($e);
            $this->conflicto($reporte['conflictos'], 'gestor', (string) $datos['usuario'], 'No se pudo guardar el gestor: '.$e->getMessage());

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Asociación de cuentas
    // -----------------------------------------------------------------

    /**
     * Persiste (o simula en dry-run) las cuentas del gestor en todos los
     * subsistemas relevantes: la propia de GLPI ademas de las enumeradas.
     *
     * @return list<string> fingerprints consumidos
     */
    private function asociarCuentas(
        GestorUser $gestor,
        array $datos,
        RemoteAccount $cuentaGlpi,
        IndicesImportacion $indices,
        string $cpf,
        bool $dryRun,
        array &$reporte,
    ): array {
        $consumidas = [];

        foreach ($indices->subsistemas() as $slug => $subsystem) {
            $cuenta = $cuentaGlpi->subsystemSlug === $slug
                ? $cuentaGlpi
                : $indices->buscar($slug, $cpf, $datos['usuario'], $this->emailsCandidatos($gestor, $datos, $subsystem));

            // Adagio no se enumera en bloque: la cuenta se construye desde
            // datosDeAdagio() (external_account_id ya confirmado por CPF).
            if ($cuenta === null && $slug === 'adagio' && isset($datos['external_account_id_adagio']) && $datos['external_account_id_adagio'] !== null) {
                $cuenta = new RemoteAccount(
                    subsystemSlug: 'adagio',
                    externalAccountId: (string) $datos['external_account_id_adagio'],
                    login: $datos['usuario'] ?? $cuentaGlpi->login,
                    email: $datos['email_personal'] ?? $cuentaGlpi->email,
                    cpf: $cpf,
                    estado: 'activo',
                    nombreCompleto: $datos['nombre_completo'] ?? $cuentaGlpi->nombreCompleto,
                    meta: [
                        'external_account_id' => $datos['external_account_id_adagio'],
                        'email' => $datos['email_personal'] ?? $cuentaGlpi->email,
                        'cpf' => $cpf,
                    ],
                );
            }

            if ($cuenta === null) {
                $reporte['faltantes'][] = [
                    'slug' => $slug,
                    'gestor' => (string) $gestor->usuario,
                    'cpf' => $cpf,
                    'motivo' => 'No hay en el subsistema una cuenta correlacionable con este CPF/login/email',
                ];

                continue;
            }

            $finger = $this->guardarCuenta($gestor, $subsystem, $cuenta, $dryRun, $reporte);

            if ($finger !== null) {
                $consumidas[] = $finger;
            }
        }

        return $consumidas;
    }

    private function guardarCuenta(
        GestorUser $gestor,
        Subsystem $subsystem,
        RemoteAccount $cuenta,
        bool $dryRun,
        array &$reporte,
    ): ?string {
        $existente = UserSubsystemAccount::query()
            ->where('gestor_user_id', $gestor->getKey())
            ->where('subsystem_id', $subsystem->getKey())
            ->first();

        if ($dryRun) {
            $reporte['resumen'][$existente === null ? 'cuentas_creadas' : 'cuentas_actualizadas']++;

            return $cuenta->fingerprint();
        }

        try {
            $account = UserSubsystemAccount::updateOrCreate(
                ['gestor_user_id' => $gestor->getKey(), 'subsystem_id' => $subsystem->getKey()],
                [
                    'credencial_usuario' => $cuenta->login ?? (string) $gestor->usuario,
                    'external_account_id' => $cuenta->externalAccountId,
                    'fecha_creacion' => now(),
                    'estado' => $this->mapearEstadoRemoto($cuenta->estado),
                    'meta' => $cuenta->meta,
                    'configuracion_adicional' => $cuenta->configInicial,
                ],
            );

            $reporte['resumen'][$account->wasRecentlyCreated ? 'cuentas_creadas' : 'cuentas_actualizadas']++;

            return $cuenta->fingerprint();
        } catch (Throwable $e) {
            report($e);
            $this->conflicto($reporte['conflictos'], 'cuenta', $gestor->usuario.'@'.$subsystem->slug, 'No se pudo guardar la cuenta: '.$e->getMessage());

            return null;
        }
    }

    private function mapearEstadoRemoto(string $estadoRemoto): string
    {
        return match ($estadoRemoto) {
            'activo' => SubsystemAccountStatus::Activo->value,
            'suspendido' => SubsystemAccountStatus::Suspendido->value,
            'deshabilitado', 'borrado' => SubsystemAccountStatus::Deshabilitado->value,
            default => SubsystemAccountStatus::Deshabilitado->value,
        };
    }

    // -----------------------------------------------------------------
    // Ayudas de correlación
    // -----------------------------------------------------------------

    /**
     * Emails con los que la persona pudo estar creada en el subsistema: el
     * personal del gestor y el que se forma con el dominio configurado en el
     * subsistema (la misma regla que usan los drivers al dar el alta).
     *
     * @return list<string>
     */
    private function emailsCandidatos(GestorUser $gestor, array $datos, Subsystem $subsystem): array
    {
        $emails = [];

        $personal = strtolower(trim((string) ($gestor->email_personal ?? ($datos['email_personal'] ?? ''))));
        $personal = str_contains($personal, '@') ? $personal : null;

        if (! blank($datos['email_personal'])) {
            $personal = strtolower(trim((string) $datos['email_personal']));
            $personal = str_contains($personal, '@') ? $personal : null;
        }

        if ($personal !== null) {
            $emails[] = $personal;
        }

        $dominio = $subsystem->api_config['dominio'] ?? null;

        if (filled($dominio) && filled($datos['usuario'])) {
            $emails[] = strtolower($datos['usuario']).'@'.strtolower((string) $dominio);
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * Se anota como huérfanas las cuentas remotas que no terminaron
     * asociadas a ningún gestor importado: cuentas de personas dadas de baja
     * fuera de GLPI, usuarios de pasos manuales del panel de GLPI, etc.
     *
     * @param  list<string>  $procesadas
     * @param  array{resumen: array<string, mixed>, conflictos: list<array<string, string>>, faltantes: list<array<string, string>>, huerfanos: list<array<string, ?string>>}  $reporte
     */
    private function reportarHuerfanos(IndicesImportacion $indices, array $procesadas, array &$reporte): void
    {
        $procesadasMap = array_fill_keys($procesadas, true);

        foreach ($indices->remotasTodas() as $slug => $cuenta) {
            if (isset($procesadasMap[$cuenta->fingerprint()])) {
                continue;
            }

            $reporte['huerfanos'][] = [
                'slug' => $slug,
                'external_id' => $cuenta->externalAccountId,
                'login' => $cuenta->login,
                'email' => $cuenta->email,
                'cpf' => $cuenta->cpf,
                'estado' => $cuenta->estado,
            ];
        }
    }

    /**
     * @param  list<array<string, string>>  $conflictos
     */
    private function conflicto(array &$conflictos, string $tipo, string $detalle, string $mensaje): void
    {
        $conflictos[] = ['tipo' => $tipo, 'detalle' => $detalle, 'mensaje' => $mensaje];
    }
}
