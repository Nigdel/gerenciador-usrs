<?php

namespace App\Http\Controllers;

use App\Models\Subsystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SubsystemController extends Controller
{
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
