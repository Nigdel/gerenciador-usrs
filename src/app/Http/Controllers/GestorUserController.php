<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityProviderInterface;
use App\Enums\OperationType;
use App\Exceptions\ProvisioningException;
use App\Http\Requests\GestorUserListRequest;
use App\Http\Requests\GestorUserRequest;
use App\Http\Requests\OffboardGestorUserRequest;
use App\Http\Requests\SuspendGestorUserRequest;
use App\Http\Requests\SyncGestorUserRequest;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Services\ProvisioningOperationService;
use App\Services\SubsystemServiceRegistry;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class GestorUserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioningService,
        private readonly UserSuspensionService $suspensionService,
        private readonly UserOffboardingService $offboardingService,
        private readonly UserDataSyncService $syncService,
        private readonly ProvisioningOperationService $operationService,
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    public function index(GestorUserListRequest $request): View
    {
        $this->authorize('viewAny', GestorUser::class);

        $gestorUsers = GestorUser::query()
            ->withCount('subsystemAccounts')
            ->buscar($request->busqueda())
            ->delEstado($request->estado())
            ->enSubsistema($request->subsistema())
            ->orderBy('nombre_completo')
            // withQueryString es lo que mantiene los filtros al ir a la
            // página 2; sin él el listado vuelve a mostrarlo todo.
            ->paginate(25)
            ->withQueryString();

        // El propio listado siempre trae todos los subsistemas: el desplegable
        // de filtro no puede ofrecerse solo los que ya se está filtrando.
        $subsistemas = Subsystem::query()->orderBy('nombre')->get();

        return view('gestor-users.index', [
            'gestorUsers' => $gestorUsers,
            'subsistemas' => $subsistemas,
            'filtros' => $request->only('q', 'estado', 'subsistema'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', GestorUser::class);

        return view('gestor-users.create', [
            'subsystems' => Subsystem::query()->activos()->orderBy('nombre')->get(),
        ]);
    }

    public function lookupByCpf(Request $request): JsonResponse
    {
        $this->authorize('create', GestorUser::class);

        $cpf = $request->validate([
            'cpf' => ['required', 'string', 'max:20'],
        ])['cpf'];

        try {
            $identityProvider = $this->registry->resolveIdentityProvider();

            if (! $identityProvider instanceof IdentityProviderInterface) {
                throw new ProvisioningException('El proveedor de identidad no admite búsquedas por CPF.');
            }

            $user = $identityProvider->findByCpf($cpf);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'No se pudo consultar el CPF en Adagio.',
            ], 503);
        }

        if (! $user) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found' => true,
            'user' => array_filter([
                'cpf' => $user['cpf'] ?? $cpf,
                'nombre_completo' => $user['nombre_completo'] ?? null,
                'email_personal' => $user['email_personal'] ?? null,
                'usuario' => $user['usuario'] ?? null,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Alta de un usuario (Fase 3.2).
     *
     * Se guarda el usuario y se propone el login de forma síncrona —una
     * consulta a Adagio y un INSERT, y el operador necesita la ficha ya
     * creada para poder redirigir— y se encola el alta en cada subsistema. La
     * respuesta dice "en curso", nunca "creado": en este punto ninguna cuenta
     * se ha tocado todavía.
     */
    public function store(GestorUserRequest $request): RedirectResponse
    {
        $this->authorize('create', GestorUser::class);

        try {
            $resultado = $this->provisioningService->provisionar($request->validated());
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.create')
                ->withInput()
                ->with('error', $this->mensajeAlOperador($exception));
        }

        $gestorUser = $resultado['gestor_user'];
        $subsistemas = $resultado['subsistemas'];

        if ($subsistemas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario se creó, pero no hay subsistemas activos donde darle de alta.');
        }

        $operacion = $this->operationService->describir(
            OperationType::Alta,
            $gestorUser,
            $subsistemas,
            // El payload va cifrado (encrypted:array) y lleva la contraseña
            // general, que es lo que los drivers necesitan para crearla.
            $resultado['datos'],
        );

        $this->operationService->despachar($operacion);

        return $this->redirigirTrasEncolar(
            $gestorUser,
            $operacion,
            $resultado['login_no_verificado'] ?? null,
        );
    }

    public function show(GestorUser $gestorUser): View
    {
        $this->authorize('view', $gestorUser);

        return view('gestor-users.show', [
            'gestorUser' => $gestorUser->load('subsystemAccounts.subsystem'),
            // Es un panel de consulta rápida: 50 entradas más recientes.
            'historial' => $gestorUser->historial()->limit(50)->get(),
            // Las 10 últimas operaciones con su detalle por cuenta, que es lo
            // que alimenta el polling del panel (Fase 3.2).
            'operaciones' => $gestorUser->operaciones()->with('cuentas')->limit(10)->get(),
        ]);
    }

    /**
     * Estado de una operación, para el polling del panel (Fase 3.2).
     *
     * Es un GET de solo lectura con la misma autorización que la ficha: quien
     * puede ver al usuario puede ver lo que se le está haciendo. Solo devuelve
     * el desglose por cuenta —subsistema, estado, mensaje e intentos—, nunca
     * el payload, que en el alta lleva la contraseña general.
     */
    public function operacion(
        GestorUser $gestorUser,
        string $operacion,
        ProvisioningOperationService $operaciones,
    ): JsonResponse {
        $this->authorize('view', $gestorUser);

        // 404 y no 403 a propósito: si la operación es de otra persona, su
        // existencia tampoco es información que deba darse.
        abort_unless(
            $gestorUser->operaciones()->where('uuid', $operacion)->exists(),
            404,
        );

        return response()->json(
            $operaciones->serializar($gestorUser->operaciones()->where('uuid', $operacion)->firstOrFail()),
        );
    }

    /**
     * Reintenta una cuenta que terminó con error (Sprint 1.2).
     *
     * Autorizar con la ability del tipo de operación y no con una propia: el
     * reintento repite la acción original, así que no puede autorizar a más
     * gente que la que podía hacerla la primera vez.
     */
    public function reintentar(
        GestorUser $gestorUser,
        string $operacion,
        int $cuenta,
        ProvisioningOperationService $operaciones,
    ): RedirectResponse {
        $fila = $gestorUser->operaciones()
            ->where('uuid', $operacion)
            ->firstOrFail()
            ->cuentas()
            ->findOrFail($cuenta);

        $this->authorize($fila->operacion->tipo->ability(), $gestorUser);

        try {
            $operaciones->reintentar($fila);
        } catch (ProvisioningException $exception) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('success', sprintf(
                'Reintento en curso para %s. El resultado aparecerá en esta misma ficha.',
                $fila->subsistema ?: 'el subsistema',
            ));
    }

    /**
     * Baja completa (Fase 2.6): deshabilita la cuenta en todos los subsistemas
     * y marca al usuario como dado de baja.
     *
     * Desde la Fase 3.2 sale de la petición: se comprueban las precondiciones y
     * se encola una cuenta por job. El usuario **no** queda marcado de baja
     * todavía —eso ocurre cuando la operación se cierra sin errores, en
     * ProvisioningOperationService::cerrar()—, así que el mensaje dice
     * "en curso" y no "dado de baja".
     */
    public function offboard(OffboardGestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('offboard', $gestorUser);

        try {
            $this->offboardingService->validarBaja($gestorUser);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $this->mensajeAlOperador($exception));
        }

        $cuentas = $this->offboardingService->cuentasABajas($gestorUser);

        if ($cuentas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en subsistemas que dar de baja.');
        }

        $motivo = $request->validated('motivo_baja');

        $operacion = $this->operationService->describir(
            OperationType::Baja,
            $gestorUser,
            $cuentas,
            ['motivo_baja' => $motivo],
        );

        $this->operationService->despachar($operacion);

        return $this->redirigirTrasEncolar($gestorUser, $operacion);
    }

    /**
     * Revierte una baja: reactiva las cuentas en los subsistemas y devuelve al
     * usuario a activo.
     */
    public function reactivate(GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('offboard', $gestorUser);

        try {
            $this->offboardingService->validarReactivacion($gestorUser);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $this->mensajeAlOperador($exception));
        }

        $cuentas = $this->offboardingService->cuentasAReactivar($gestorUser);

        if ($cuentas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('success', 'No hay cuentas dadas de baja que reactivar.');
        }

        $operacion = $this->operationService->describir(
            OperationType::Reactivacion,
            $gestorUser,
            $cuentas,
        );

        $this->operationService->despachar($operacion);

        return $this->redirigirTrasEncolar($gestorUser, $operacion);
    }

    public function edit(GestorUser $gestorUser): View
    {
        $this->authorize('update', $gestorUser);

        return view('gestor-users.edit', compact('gestorUser'));
    }

    /**
     * Propaga los datos de contacto del usuario a sus cuentas en subsistemas
     * (Fase 2.7).
     *
     * Es una acción aparte del guardado y no automática a propósito: el
     * operador decide cuándo salir a los subsistemas, en lugar de que corregir
     * una dirección dispare N llamadas externas sin querer.
     */
    public function syncSubsystems(SyncGestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('update', $gestorUser);

        $cuentas = $this->syncService->cuentasASincronizar(
            $gestorUser,
            $request->validated('subsistemas') ?: null,
        );

        if ($cuentas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en los subsistemas seleccionados.');
        }

        $operacion = $this->operationService->describir(
            OperationType::Sincronizacion,
            $gestorUser,
            $cuentas,
        );

        $this->operationService->despachar($operacion);

        return $this->redirigirTrasEncolar($gestorUser, $operacion);
    }

    public function update(GestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('update', $gestorUser);

        $data = $request->validated();

        if (blank($data['password_general'] ?? null)) {
            unset($data['password_general']);
        }

        unset($data['subsistemas']);
        $gestorUser->update($data);

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('success', 'Datos del usuario actualizados correctamente.');
    }

    public function destroy(GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('delete', $gestorUser);

        if ($gestorUser->subsystemAccounts()->exists()) {
            return redirect()
                ->route('gestor-users.index')
                ->with('error', 'No se puede eliminar un usuario que tiene cuentas en subsistemas.');
        }

        $gestorUser->delete();

        return redirect()
            ->route('gestor-users.index')
            ->with('success', 'Usuario eliminado correctamente.');
    }

    /**
     * Suspensión en uno, varios o todos los subsistemas (Fases 2.3 y 3.2).
     *
     * Los datos de la suspensión (motivo y fechas) viajan en el payload de la
     * operación, no en el job: el job se serializa y las fechas se leen del
     * JSON de la base, así que se guardan como texto ISO.
     */
    public function suspend(SuspendGestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('suspend', $gestorUser);

        $cuentas = $this->suspensionService->cuentasASuspender(
            $gestorUser,
            $request->validated('subsistemas') ?: null,
        );

        if ($cuentas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en los subsistemas seleccionados.');
        }

        $datos = $this->suspensionService->datosDeSuspension([
            'motivo_suspension' => $request->validated('motivo_suspension'),
            'inicio_suspension' => $request->validated('inicio_suspension') ?: null,
            'fin_suspension' => $request->validated('fin_suspension') ?: null,
        ]);

        $operacion = $this->operationService->describir(
            OperationType::Suspension,
            $gestorUser,
            $cuentas,
            // Los mismos datos van también por cuenta: es lo único que el job
            // necesita para ejecutar una, y así no depende de releer la
            // cabecera.
            ['motivo_suspension' => $datos['motivo_suspension']],
            array_fill_keys($cuentas->pluck('id')->all(), $datos),
        );

        $this->operationService->despachar($operacion);

        return $this->redirigirTrasEncolar($gestorUser, $operacion);
    }

    /**
     * Redirección común de las cuatro acciones encoladas.
     *
     * El mensaje es deliberadamente «en curso» y no «hecho»: en este punto
     * ningún subsistema se ha tocado todavía, y decir lo contrario sería
     * mentirle al operador sobre el estado real de sus cuentas.
     */
    private function redirigirTrasEncolar(
        GestorUser $gestorUser,
        ProvisioningOperation $operacion,
        ?string $aviso = null,
    ): RedirectResponse {
        $mensaje = sprintf(
            '%s (%d cuenta(s) en cola). El resultado aparecerá en esta misma ficha.',
            $operacion->tipo->aviso(),
            $operacion->cuentas()->count(),
        );

        // El aviso extra (el login sin verificar del 2.8, por ejemplo) y el de
        // la cola se juntan en un único mensaje: los toasts se renderizan
        // todos en la misma posición fija, así que dos claves a la vez se
        // solaparían.
        $mensajes = array_filter([$aviso, $mensaje]);

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with(count($mensajes) > 1 ? 'warning' : 'success', implode(' ', $mensajes));
    }

    public function resetPassword(GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('resetPassword', $gestorUser);

        try {
            $resultado = $this->provisioningService->resetAllPasswords($gestorUser);
            $results = $resultado['resultados'];
            $fallos = collect($results)->where('exito', false);

            // La contraseña va en el flash de una sola vez y no se guarda en
            // ningún sitio: en la siguiente petición ya no está. Por eso la
            // vista tiene que avisar de que no se va a repetir.
            $flash = ['contrasena_temporal' => $resultado['contrasena']];

            if ($fallos->isEmpty()) {
                return redirect()
                    ->route('gestor-users.show', $gestorUser)
                    ->with($flash + ['success' => 'Contraseñas restablecidas correctamente.']);
            }

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with($flash + [
                    'warning' => 'Algunas contraseñas no pudieron restablecerse.',
                    'provisioning_results' => $results,
                ]);
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $this->mensajeAlOperador($exception, 'No se pudieron restablecer las contraseñas'));
        }
    }

    /**
     * Traduce una excepción a lo que ve el operador (Sprint 2.1).
     *
     * Solo ProvisioningException —y OperationInProgressException, que hereda de
     * ella— enseñan su mensaje: describen una situación que un humano entiende
     * y sobre la que puede actuar. Cualquier otra cosa se reporta en el log y
     * se muestra como un fallo genérico, porque su texto puede traer un nombre
     * de columna, una URL o un rastro de pila, que no le sirven de nada al
     * operador y sí de mucho a quien esté mirando su pantalla.
     */
    private function mensajeAlOperador(Throwable $exception, string $prefijo = ''): string
    {
        $mensaje = $exception instanceof ProvisioningException
            ? $exception->getMessage()
            : 'Ocurrió un error inesperado. Inténtalo de nuevo o avisa a sistemas.';

        return $prefijo === '' ? $mensaje : $prefijo.': '.$mensaje;
    }
}
