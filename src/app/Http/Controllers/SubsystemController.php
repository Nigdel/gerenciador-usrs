<?php

namespace App\Http\Controllers;

use App\Contracts\IdentityProviderInterface;
use App\Models\Subsystem;
use App\Services\SubsystemServiceRegistry;
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
        return view('subsystems.show', [
            'subsystem' => $subsystem->loadCount('accounts'),
        ]);
    }

    public function testConnection(Subsystem $subsystem): RedirectResponse
    {
        if (! $subsystem->es_proveedor_identidad) {
            return redirect()
                ->route('subsystems.show', $subsystem)
                ->with('error', 'Solo se puede probar la conexión del proveedor de identidad.');
        }

        try {
            $service = $this->registry->resolve($subsystem->slug);

            if (! $service instanceof IdentityProviderInterface) {
                throw new \InvalidArgumentException('El proveedor de identidad no admite pruebas de conexión.');
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
