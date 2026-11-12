<?php

namespace App\Services\Termos;

use App\Enums\TermoEstado;
use App\Enums\TermoTipo;
use App\Models\GestorUser;
use App\Models\Termo;
use App\Models\User;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiMediosService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo de vida de los termos de responsabilidad.
 *
 * El servicio concentra las reglas que no pueden quedar en la vista ni en el
 * controlador: que el medio siga siendo del usuario en GLPI, que no haya dos
 * termos vigentes del mismo equipo, qué estados permiten subir el firmado y
 * cómo se congela el snapshot que luego imprime el PDF.
 *
 * La fuente de los medios es GLPI, pero el termo vive aquí. No se escribe de
 * vuelta en GLPI: el plugin original guardaba en su propia tabla y esta
 * implementación hace lo mismo, en la base del gestor.
 */
class TermoService
{
    public function __construct(
        private readonly GlpiMediosService $medios,
        private readonly TermoPdfService $pdf,
    ) {}

    /**
     * La cuenta de GLPI del gestor, que es la que declara sus medios.
     */
    public function cuentaGlpi(GestorUser $gestorUser): ?UserSubsystemAccount
    {
        $cuentas = $gestorUser->relationLoaded('subsystemAccounts')
            ? $gestorUser->subsystemAccounts
            : $gestorUser->subsystemAccounts()->with('subsystem')->get();

        return $cuentas->first(
            fn (UserSubsystemAccount $cuenta): bool => $cuenta->subsystem?->slug === 'glpi',
        );
    }

    /**
     * Los datos con los que se rellena el formulario de generación: usuario,
     * empresa y medio, ya resueltos desde GLPI.
     *
     * @return array{usuario: array<string, ?string>, empresa: array<string, ?string>, dispositivo: array<string, ?string>, tipos: list<array{valor: string, etiqueta: string}>, estado_conservacion: ?string}
     *
     * @throws ValidationException si el gestor no tiene cuenta de GLPI o el medio no le pertenece
     */
    public function formulario(GestorUser $gestorUser, string $itemType, int|string $itemId): array
    {
        $cuenta = $this->cuentaGlpi($gestorUser);

        if ($cuenta === null || $cuenta->subsystem === null) {
            throw ValidationException::withMessages([
                'item_id' => 'El gestor no tiene cuenta en GLPI: no se pueden consultar sus medios.',
            ]);
        }

        if (! $this->medios->perteneceAlUsuario($cuenta->subsystem, $itemType, (string) $itemId, (string) $cuenta->external_account_id)) {
            throw ValidationException::withMessages([
                'item_id' => 'El medio no está atribuido a este usuario en GLPI.',
            ]);
        }

        $dispositivo = $this->medios->detalleTermo($cuenta->subsystem, $itemType, (string) $itemId);

        if ($dispositivo === null) {
            throw ValidationException::withMessages([
                'item_id' => 'No se pudo leer el medio en el inventario de GLPI.',
            ]);
        }

        return [
            'usuario' => [
                'nombre' => $gestorUser->nombre_completo,
                'cpf' => $gestorUser->cpf,
                'email' => $gestorUser->email_personal,
                'empresa' => $gestorUser->empresa,
            ],
            'empresa' => $this->empresa($gestorUser),
            'dispositivo' => $dispositivo,
            'tipos' => array_map(
                static fn (TermoTipo $tipo): array => [
                    'valor' => $tipo->value,
                    'etiqueta' => $tipo->etiqueta(),
                ],
                TermoTipo::cases(),
            ),
            'estado_conservacion' => $dispositivo['estado'] ?? null,
        ];
    }

    /**
     * Emite un termo nuevo y su PDF.
     *
     * El snapshot se arma con lo que devolvió GLPI y con los ajustes que el
     * operador haya hecho en el formulario (CNPJ, procesador, color, RAM,
     * almacenamiento, MAC). Los datos de identidad del usuario y del medio no
     * se aceptan desde el formulario: son los que declara el inventario.
     *
     * @param  array<string, mixed>  $datos
     *
     * @throws ValidationException
     */
    public function crear(GestorUser $gestorUser, array $datos, User $actor): Termo
    {
        $itemType = (string) $datos['item_type'];
        $itemId = (int) $datos['item_id'];
        $tipo = TermoTipo::from((string) $datos['tipo']);

        $formulario = $this->formulario($gestorUser, $itemType, $itemId);

        if ($this->vigentePara($gestorUser, $itemType, $itemId) !== null) {
            throw ValidationException::withMessages([
                'item_id' => 'Este medio ya tiene un termo vigente. Anúlalo antes de emitir otro.',
            ]);
        }

        $dispositivo = $formulario['dispositivo'];
        $empresa = $formulario['empresa'];

        foreach (['procesador', 'color', 'ram', 'almacenamiento', 'mac'] as $campo) {
            if (isset($datos[$campo]) && trim((string) $datos[$campo]) !== '') {
                $dispositivo[$campo] = trim((string) $datos[$campo]);
            }
        }

        if (isset($datos['estado_conservacion']) && trim((string) $datos['estado_conservacion']) !== '') {
            $dispositivo['estado'] = trim((string) $datos['estado_conservacion']);
        }

        if (isset($datos['cnpj']) && trim((string) $datos['cnpj']) !== '') {
            $empresa['cnpj'] = trim((string) $datos['cnpj']);
        }

        $cuenta = $this->cuentaGlpi($gestorUser);

        $termo = Termo::create([
            'gestor_user_id' => $gestorUser->id,
            'user_subsystem_account_id' => $cuenta?->id,
            'item_type' => $itemType,
            'item_id' => $itemId,
            'item_name' => $dispositivo['nombre'] ?? null,
            'item_serial' => $dispositivo['serial'] ?? null,
            'tipo' => $tipo,
            'estado' => TermoEstado::Generado,
            'datos' => [
                'empresa' => $empresa,
                'usuario' => $formulario['usuario'],
                'dispositivo' => $dispositivo,
                'termo' => [
                    'observaciones' => isset($datos['observaciones']) ? trim((string) $datos['observaciones']) : null,
                ],
            ],
            'generado_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        try {
            $ruta = $this->pdf->generar($termo);
        } catch (Throwable $exception) {
            // Sin PDF no hay termo: el registro a medias dejaría un documento
            // que se puede listar pero no abrir.
            $termo->delete();

            throw $exception;
        }

        $termo->update(['pdf_generado' => $ruta]);

        return $termo->refresh();
    }

    /**
     * Marca el termo como enviado al colaborador para su firma.
     */
    public function marcarEnviado(Termo $termo, User $actor): Termo
    {
        if ($termo->estado !== TermoEstado::Generado) {
            throw ValidationException::withMessages([
                'estado' => 'Solo un termo generado puede marcarse como enviado.',
            ]);
        }

        $termo->update([
            'estado' => TermoEstado::Enviado,
            'enviado_at' => now(),
            'updated_by' => $actor->id,
        ]);

        return $termo->refresh();
    }

    /**
     * Guarda el PDF firmado que devuelve el colaborador.
     */
    public function subirFirmado(Termo $termo, UploadedFile $archivo, User $actor): Termo
    {
        if (! $termo->permiteSubirFirmado()) {
            throw ValidationException::withMessages([
                'pdf_firmado' => 'Un termo firmado o anulado no admite un nuevo PDF firmado.',
            ]);
        }

        $directorio = trim((string) config('termos.directorio', 'termos'), '/')
            ."/{$termo->gestor_user_id}";

        $nombre = "firmado-{$termo->id}-".now()->format('YmdHis').'.pdf';

        $ruta = Storage::disk('local')->putFileAs($directorio, $archivo, $nombre);

        if ($ruta === false) {
            throw ValidationException::withMessages([
                'pdf_firmado' => 'No se pudo guardar el PDF firmado.',
            ]);
        }

        $anterior = $termo->pdf_firmado;

        $termo->update([
            'estado' => TermoEstado::Firmado,
            'pdf_firmado' => $ruta,
            'firmado_at' => now(),
            'updated_by' => $actor->id,
        ]);

        if (is_string($anterior) && $anterior !== '' && $anterior !== $ruta) {
            Storage::disk('local')->delete($anterior);
        }

        return $termo->refresh();
    }

    /**
     * Anula un termo vigente sin borrar sus archivos ni su snapshot.
     */
    public function anular(Termo $termo, string $motivo, User $actor): Termo
    {
        if (! $termo->permiteAnular()) {
            throw ValidationException::withMessages([
                'motivo_anulacion' => 'Este termo ya no se puede anular.',
            ]);
        }

        $termo->update([
            'estado' => TermoEstado::Anulado,
            'anulado_at' => now(),
            'motivo_anulacion' => $motivo,
            'updated_by' => $actor->id,
        ]);

        return $termo->refresh();
    }

    /**
     * El termo vigente de un medio, si lo hay.
     */
    public function vigentePara(GestorUser $gestorUser, string $itemType, int|string $itemId): ?Termo
    {
        return Termo::query()
            ->where('gestor_user_id', $gestorUser->id)
            ->delMedio($itemType, $itemId)
            ->vigentes()
            ->latest('id')
            ->first();
    }

    /**
     * El último termo de cada medio, indexado por "Tipo:id".
     *
     * Se lee de una vez todo el historial del gestor: la ficha puede tener
     * pocos medios y así el listado no hace una consulta por fila.
     *
     * @return array<string, Termo>
     */
    public function ultimosPorMedio(GestorUser $gestorUser): array
    {
        $ultimos = [];

        foreach ($this->historial($gestorUser) as $termo) {
            $clave = $termo->item_type.':'.$termo->item_id;

            if (! isset($ultimos[$clave])) {
                $ultimos[$clave] = $termo;
            }
        }

        return $ultimos;
    }

    /**
     * Todos los termos del gestor, del más reciente al más antiguo.
     *
     * @return Collection<int, Termo>
     */
    public function historial(GestorUser $gestorUser): Collection
    {
        return Termo::query()
            ->where('gestor_user_id', $gestorUser->id)
            ->with('creadoPor')
            ->latest('id')
            ->get();
    }

    /**
     * Los datos legales de la empresa del gestor, con la empresa por defecto
     * como respaldo.
     *
     * @return array{clave: string, nombre: string, cnpj: string, ciudad: string, logo: string}
     */
    private function empresa(GestorUser $gestorUser): array
    {
        $clave = strtolower(trim((string) $gestorUser->empresa));
        $empresas = (array) config('termos.empresas', []);
        $defecto = (string) config('termos.empresa_por_defecto', 'klios');

        $empresa = $empresas[$clave] ?? $empresas[$defecto] ?? [];

        return [
            'clave' => $clave !== '' ? $clave : $defecto,
            'nombre' => (string) ($empresa['nombre'] ?? $defecto),
            'cnpj' => (string) ($empresa['cnpj'] ?? ''),
            'ciudad' => (string) ($empresa['ciudad'] ?? 'Goiânia/GO'),
            'logo' => (string) ($empresa['logo'] ?? ''),
        ];
    }
}
