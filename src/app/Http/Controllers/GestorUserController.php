<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityProviderInterface;
use App\Http\Requests\GestorUserRequest;
use App\Http\Requests\OffboardGestorUserRequest;
use App\Http\Requests\SuspendGestorUserRequest;
use App\Http\Requests\SyncGestorUserRequest;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Services\SubsystemServiceRegistry;
use App\Services\UserDataSyncService;
use App\Services\UserOffboardingService;
use App\Services\UserProvisioningService;
use App\Services\UserSuspensionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class GestorUserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioningService,
        private readonly UserSuspensionService $suspensionService,
        private readonly UserOffboardingService $offboardingService,
        private readonly UserDataSyncService $syncService,
        private readonly SubsystemServiceRegistry $registry,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', GestorUser::class);

        return view('gestor-users.index', [
            'gestorUsers' => GestorUser::query()
                ->withCount('subsystemAccounts')
                ->orderBy('nombre_completo')
                ->get(),
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
                throw new RuntimeException('El proveedor de identidad no admite búsquedas por CPF.');
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

    public function store(GestorUserRequest $request): RedirectResponse
    {
        $this->authorize('create', GestorUser::class);

        try {
            $resultado = $this->provisioningService->provisionar($request->validated());
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.create')
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $fallos = collect($resultado['resultados'])->where('exito', false);

        $redirect = redirect()->route('gestor-users.show', $resultado['gestor_user']);

        if ($fallos->isEmpty()) {
            return $redirect->with('success', 'Usuario y cuentas creados correctamente.');
        }

        return $redirect
            ->with('warning', 'El usuario fue creado, pero algunas cuentas no pudieron aprovisionarse.')
            ->with('provisioning_results', $resultado['resultados']);
    }

    public function show(GestorUser $gestorUser): View
    {
        $this->authorize('view', $gestorUser);

        return view('gestor-users.show', [
            'gestorUser' => $gestorUser->load('subsystemAccounts.subsystem'),
            // Es un panel de consulta rápida: 50 entradas más recientes.
            'historial' => $gestorUser->historial()->limit(50)->get(),
        ]);
    }

    /**
     * Baja completa (Fase 2.6): deshabilita la cuenta en todos los subsistemas
     * y marca al usuario como dado de baja.
     */
    public function offboard(OffboardGestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('offboard', $gestorUser);

        try {
            $resultado = $this->offboardingService->darDeBaja($gestorUser, $request->validated('motivo_baja'));
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $exception->getMessage());
        }

        $resultados = $resultado['resultados'];
        $fallos = collect($resultados)->where('exito', false);

        if ($resultados === []) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en subsistemas que dar de baja.');
        }

        if ($fallos->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('success', 'Usuario dado de baja correctamente.');
        }

        // El usuario NO queda marcado de baja si alguna cuenta falló: el
        // servicio se encarga de eso. Aquí solo se informa de qué falló.
        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('warning', 'La baja se completó solo en algunos subsistemas. Revisa el detalle antes de repetirla.')
            ->with('provisioning_results', $resultados);
    }

    /**
     * Revierte una baja: reactiva las cuentas en los subsistemas y devuelve al
     * usuario a activo.
     */
    public function reactivate(GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('offboard', $gestorUser);

        try {
            $resultado = $this->offboardingService->reactivar($gestorUser);
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', $exception->getMessage());
        }

        $fallos = collect($resultado['resultados'])->where('exito', false);

        if ($fallos->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('success', 'Usuario reactivado correctamente.');
        }

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('warning', 'Algunas cuentas no pudieron reactivarse.')
            ->with('provisioning_results', $resultado['resultados']);
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

        $resultado = $this->syncService->sincronizar(
            $gestorUser,
            $request->validated('subsistemas') ?: null,
        );

        $resultados = $resultado['resultados'];

        if ($resultados === []) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en los subsistemas seleccionados.');
        }

        $fallos = collect($resultados)->where('exito', false);

        if ($fallos->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('success', 'Datos sincronizados con los subsistemas.');
        }

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('warning', 'Los datos se actualizaron solo en algunos subsistemas.')
            ->with('provisioning_results', $resultados);
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

    public function suspend(SuspendGestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('suspend', $gestorUser);

        try {
            $resultados = $this->suspensionService->suspender([
                'usuario' => $gestorUser->usuario,
                'subsistemas' => $request->validated('subsistemas') ?: null,
                'motivo_suspension' => $request->validated('motivo_suspension'),
                'inicio_suspension' => $request->validated('inicio_suspension') ?: null,
                'fin_suspension' => $request->validated('fin_suspension') ?: null,
            ]);
        } catch (RuntimeException|ModelNotFoundException $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', 'No se pudo suspender: '.$exception->getMessage());
        }

        $fallos = collect($resultados)->where('exito', false);

        if ($resultados === []) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('warning', 'El usuario no tiene cuentas en los subsistemas seleccionados.');
        }

        if ($fallos->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('success', 'Cuentas suspendidas correctamente.');
        }

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('warning', 'Algunas cuentas no pudieron suspenderse.')
            ->with('provisioning_results', $resultados);
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
        } catch (RuntimeException $exception) {
            report($exception);

            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', 'No se pudieron restablecer las contraseñas: '.$exception->getMessage());
        }
    }
}
