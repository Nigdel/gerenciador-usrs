<?php

namespace App\Http\Controllers;

use App\Contracts\SubsystemConnectionInterface;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\AuditService;
use App\Services\Subsystems\BaseSubsystemService;
use App\Services\SubsystemServiceRegistry;
use BackedEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SubsystemController extends Controller
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Subsystem::class);

        $busqueda = trim((string) $request->query('q'));

        $subsistemas = Subsystem::query()
            ->withCount('accounts')
            ->when($busqueda !== '', fn ($query) => $query->where(
                fn ($query) => $query->where('nombre', 'like', '%'.$busqueda.'%')
                    ->orWhere('slug', 'like', '%'.$busqueda.'%')
            ))
            ->orderBy('nombre')
            ->paginate(25)
            ->withQueryString();

        return view('subsystems.index', [
            'subsystems' => $subsistemas,
            'busqueda' => $busqueda,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Subsystem::class);

        return view('subsystems.create');
    }

    public function show(Subsystem $subsystem): View
    {
        $this->authorize('view', $subsystem);

        $connectionTestable = false;

        try {
            $connectionTestable = $this->registry->resolve($subsystem->slug) instanceof SubsystemConnectionInterface;
        } catch (Throwable) {
            // El detalle del subsistema sigue siendo accesible aunque no exista driver.
        }

        return view('subsystems.show', [
            'subsystem' => $subsystem->load(['accounts.user'])->loadCount('accounts'),
            'connectionTestable' => $connectionTestable,
        ]);
    }

    public function chatwootTeams(Subsystem $subsystem, Request $request): JsonResponse
    {
        $this->authorize('view', $subsystem);

        if ($subsystem->slug !== 'chatwoot') {
            return response()->json([
                'message' => 'Este endpoint solo aplica a Chatwoot.',
            ], 400);
        }

        $empresa = strtolower(trim((string) $request->query('empresa', '')));

        if ($empresa === '') {
            return response()->json([
                'message' => 'Debe indicar la empresa para consultar los equipos de Chatwoot.',
            ], 422);
        }

        try {
            $service = $this->registry->resolve($subsystem->slug);

            if (! method_exists($service, 'listTeams')) {
                throw new \RuntimeException('El subsistema Chatwoot no expone equipos disponibles.');
            }

            return response()->json([
                'teams' => $service->listTeams($subsystem, $empresa),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function accountAction(Request $request, Subsystem $subsystem, UserSubsystemAccount $userSubsystemAccount): RedirectResponse
    {
        abort_if($userSubsystemAccount->subsystem_id !== $subsystem->id, 404);

        $operation = $request->validate([
            'operation' => ['required', Rule::in(['disable', 'enable', 'delete'])],
        ])['operation'];

        $this->authorize(match ($operation) {
            'disable' => 'disable',
            'enable' => 'reactivate',
            'delete' => 'delete',
        }, $userSubsystemAccount);

        if ($operation === 'delete') {
            try {
                $service = $this->registry->resolve($subsystem->slug);

                if ($service instanceof BaseSubsystemService && $service->supportsDeleteUser()) {
                    $result = $service->deleteUser($userSubsystemAccount);

                    if (! $result->success) {
                        return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                            ->with('error', $result->mensaje ?? 'No se pudo eliminar la cuenta en el subsistema.');
                    }
                }
            } catch (Throwable $exception) {
                report($exception);

                return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                    ->with('error', $exception->getMessage());
            }

            $userSubsystemAccount->delete();

            return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                ->with('success', 'Cuenta eliminada correctamente.');
        }

        try {
            $service = $this->registry->resolve($subsystem->slug);
            $result = $operation === 'enable'
                ? $service->reactivateUser($userSubsystemAccount)
                : $service->disableUser($userSubsystemAccount);
        } catch (Throwable $exception) {
            report($exception);

            return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                ->with('error', $exception->getMessage());
        }

        $confirmation = null;
        $expectedState = $operation === 'enable' ? 'activo' : 'deshabilitado';
        $acceptedStates = $operation === 'enable'
            ? ['activo']
            : ['deshabilitado', 'suspendido'];
        if ($result->success) {
            $config = $subsystem->api_config ?? [];
            $attempts = max(1, min((int) ($config['state_confirmation_attempts'] ?? 1), 10));
            $delayMilliseconds = max(0, min((int) ($config['state_confirmation_delay_ms'] ?? 0), 5000));

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $confirmation = $service->getUserStatus($userSubsystemAccount);

                if ($confirmation->success && in_array($confirmation->estado, $acceptedStates, true)) {
                    break;
                }

                if ($attempt < $attempts && $delayMilliseconds > 0) {
                    usleep($delayMilliseconds * 1000);
                }
            }

            if (! $confirmation->success || ! in_array($confirmation->estado, $acceptedStates, true)) {
                return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                    ->with('error', 'El subsistema no confirmó el cambio de estado de la cuenta.');
            }
        }

        if ($result->success) {
            // Al reactivar se limpia además el rastro de la suspensión (Fase 2.5);
            // al deshabilitar no, porque no viene de una suspensión nuestra.
            $atributos = $operation === 'enable'
                ? $userSubsystemAccount->atributosAlReactivar()
                : ['estado' => $expectedState];

            $userSubsystemAccount->update($atributos + ['meta' => $result->raw]);
        }

        // El cambio de estado ya deja rastro en account_state_logs por el
        // observer; esta entrada añade lo que allí no cabe, que es que lo hizo
        // una persona desde la ficha del subsistema y no un proceso.
        if ($result->success) {
            $this->audit->log(AuditService::CUENTA_ACCION, [
                'accion' => $operation,
                'gestor_user_id' => $userSubsystemAccount->gestor_user_id,
                'subsistema' => $subsystem->slug,
                'cuenta_id' => $userSubsystemAccount->id,
                'estado' => $userSubsystemAccount->estado->value,
            ]);
        }

        return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
            ->with($result->success ? 'success' : 'error', $result->mensaje ?? ($result->success ? 'Operación completada.' : 'No se pudo completar la operación.'));
    }

    private function accountActionRedirect(Request $request, Subsystem $subsystem, UserSubsystemAccount $account): RedirectResponse
    {
        if ($request->input('return_to') === 'gestor-user') {
            return redirect()->route('gestor-users.show', $account->gestor_user_id);
        }

        return redirect()->route('subsystems.show', $subsystem);
    }

    public function testConnection(Subsystem $subsystem): RedirectResponse
    {
        $this->authorize('testConnection', $subsystem);

        try {
            $service = $this->registry->resolve($subsystem->slug);

            if (! $service instanceof SubsystemConnectionInterface) {
                throw new \InvalidArgumentException('Este subsistema no admite pruebas de conexión.');
            }

            $result = $service->testConnection($subsystem);

            // Actualizar los campos de última prueba de conexión
            $subsystem->update([
                'last_connection_test_at' => now(),
                'last_connection_test_success' => $result->success,
            ]);
            $this->audit->log(AuditService::SUBSISTEMA_CONEXION_PROBADA, [
                'subsistema_id' => $subsystem->id,
                'nombre' => $subsystem->nombre,
                'exito' => $result->success,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            // Registrar el fallo en la prueba de conexión
            $subsystem->update([
                'last_connection_test_at' => now(),
                'last_connection_test_success' => false,
            ]);

            $this->audit->log(AuditService::SUBSISTEMA_CONEXION_PROBADA, [
                'subsistema_id' => $subsystem->id,
                'nombre' => $subsystem->nombre,
                'exito' => false,
            ]);

            return redirect()
                ->route('subsystems.show', $subsystem)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('subsystems.show', $subsystem)
            ->with($result->success ? 'success' : 'error', $result->mensaje);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Subsystem::class);

        $subsystem = Subsystem::create($this->validatedData($request));

        $this->audit->log(AuditService::SUBSISTEMA_CREADO, [
            'subsistema_id' => $subsystem->id,
            'nombre' => $subsystem->nombre,
            'slug' => $subsystem->slug,
        ]);

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$subsystem->nombre} fue creado correctamente.");
    }

    public function edit(Subsystem $subsystem): View
    {
        $this->authorize('update', $subsystem);

        return view('subsystems.edit', compact('subsystem'));
    }

    public function update(Request $request, Subsystem $subsystem): RedirectResponse
    {
        $this->authorize('update', $subsystem);

        $data = $this->validatedData($request, $subsystem);

        // Solo el nombre de las claves de `api_config`, nunca su valor: es el
        // campo que guarda el token del subsistema, y un cambio de
        // configuración se audita por qué claves se tocaron, no por lo que
        // valían.
        $cambios = array_diff_key($data, ['api_config' => true]);
        $antes = array_keys($subsystem->api_config ?? []);
        $despues = isset($data['api_config']) ? array_keys($data['api_config']) : $antes;

        $subsystem->update($data);

        $this->audit->log(AuditService::SUBSISTEMA_ACTUALIZADO, [
            'subsistema_id' => $subsystem->id,
            'nombre' => $subsystem->nombre,
            'cambios' => $this->diffAuditable($subsystem, $cambios),
            'api_config' => ['desde' => $antes, 'hasta' => $despues],
        ]);

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$subsystem->nombre} fue actualizado correctamente.");
    }

    public function destroy(Subsystem $subsystem): RedirectResponse
    {
        $this->authorize('delete', $subsystem);

        if ($subsystem->accounts()->exists()) {
            return redirect()
                ->route('subsystems.index')
                ->with('error', 'No se puede eliminar un subsistema que tiene cuentas asociadas.');
        }

        $name = $subsystem->nombre;
        $datos = ['subsistema_id' => $subsystem->id, 'nombre' => $name, 'slug' => $subsystem->slug];
        $subsystem->delete();

        $this->audit->log(AuditService::SUBSISTEMA_ELIMINADO, $datos);

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$name} fue eliminado correctamente.");
    }

    /**
     * Campos que cambian de verdad, sin incluir `api_config`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, mixed>>
     */
    private function diffAuditable(Subsystem $subsystem, array $data): array
    {
        $cambios = [];

        foreach ($data as $campo => $valor) {
            if ($subsystem->getOriginal($campo) === $valor) {
                continue;
            }

            $cambios[$campo] = [
                'desde' => $this->valorAuditable($subsystem->getOriginal($campo)),
                'hasta' => $this->valorAuditable($valor),
            ];
        }

        return $cambios;
    }

    private function valorAuditable(mixed $valor): mixed
    {
        if ($valor instanceof BackedEnum) {
            return $valor->value;
        }

        return is_scalar($valor) || $valor === null ? $valor : 'cambiado';
    }

    private function validatedData(Request $request, ?Subsystem $subsystem = null): array
    {
        $subsystemId = $subsystem?->id;

        $validated = $request->validate([
            'nombre' => ['required', 'string', 'max:255', Rule::unique('subsystems', 'nombre')->ignore($subsystemId)],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('subsystems', 'slug')->ignore($subsystemId)],
            'descripcion' => ['nullable', 'string'],
            'api_url' => ['nullable', 'url', 'max:255'],
            'api_config' => ['nullable', 'json'],
            'external_subsystem_id' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
            'es_proveedor_identidad' => ['nullable', 'boolean'],
        ]);

        foreach (['activo', 'es_proveedor_identidad'] as $boolean) {
            $validated[$boolean] = $request->boolean($boolean);
        }

        if (isset($validated['api_config'])) {
            $validated['api_config'] = json_decode($validated['api_config'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $validated;
    }
}
