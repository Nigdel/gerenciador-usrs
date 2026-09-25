<?php

namespace App\Http\Controllers;

use App\Contracts\SubsystemConnectionInterface;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\SubsystemServiceRegistry;
use App\Services\Subsystems\BaseSubsystemService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SubsystemController extends Controller
{
    public function __construct(
        private readonly SubsystemServiceRegistry $registry,
    ) {
    }

    public function index(): View
    {
        return view('subsystems.index', [
            'subsystems' => Subsystem::query()->withCount('accounts')->orderBy('nombre')->get(),
        ]);
    }

    public function create(): View
    {
        return view('subsystems.create');
    }

    public function show(Subsystem $subsystem): View
    {
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

    public function accountAction(Request $request, Subsystem $subsystem, UserSubsystemAccount $userSubsystemAccount): RedirectResponse
    {
        abort_if($userSubsystemAccount->subsystem_id !== $subsystem->id, 404);

        $operation = $request->validate([
            'operation' => ['required', Rule::in(['disable', 'enable', 'delete'])],
        ])['operation'];

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

        if ($result->success) {
            $confirmation = $service->getUserStatus($userSubsystemAccount);

            if (! $confirmation->success || $confirmation->estado !== $expectedState) {
                return $this->accountActionRedirect($request, $subsystem, $userSubsystemAccount)
                    ->with('error', 'El subsistema no confirmó el cambio de estado de la cuenta.');
            }
        }

        if ($result->success) {
            $userSubsystemAccount->update([
                'estado' => $confirmation->estado,
                'meta' => $result->raw,
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
        try {
            $service = $this->registry->resolve($subsystem->slug);

            if (! $service instanceof SubsystemConnectionInterface) {
                throw new \InvalidArgumentException('Este subsistema no admite pruebas de conexión.');
            }

            $result = $service->testConnection($subsystem);
        } catch (Throwable $exception) {
            report($exception);

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
        $subsystem = Subsystem::create($this->validatedData($request));

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$subsystem->nombre} fue creado correctamente.");
    }

    public function edit(Subsystem $subsystem): View
    {
        return view('subsystems.edit', compact('subsystem'));
    }

    public function update(Request $request, Subsystem $subsystem): RedirectResponse
    {
        $subsystem->update($this->validatedData($request, $subsystem));

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$subsystem->nombre} fue actualizado correctamente.");
    }

    public function destroy(Subsystem $subsystem): RedirectResponse
    {
        if ($subsystem->accounts()->exists()) {
            return redirect()
                ->route('subsystems.index')
                ->with('error', 'No se puede eliminar un subsistema que tiene cuentas asociadas.');
        }

        $name = $subsystem->nombre;
        $subsystem->delete();

        return redirect()
            ->route('subsystems.index')
            ->with('success', "El subsistema {$name} fue eliminado correctamente.");
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
