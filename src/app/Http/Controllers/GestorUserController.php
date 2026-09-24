<?php

namespace App\Http\Controllers;

use App\Http\Requests\GestorUserRequest;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Services\UserProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use RuntimeException;

class GestorUserController extends Controller
{
    public function __construct(
        private readonly UserProvisioningService $provisioningService,
    ) {
    }

    public function index(): View
    {
        return view('gestor-users.index', [
            'gestorUsers' => GestorUser::query()
                ->withCount('subsystemAccounts')
                ->orderBy('nombre_completo')
                ->get(),
        ]);
    }

    public function create(): View
    {
        return view('gestor-users.create', [
            'subsystems' => Subsystem::query()->activos()->orderBy('nombre')->get(),
        ]);
    }

    public function store(GestorUserRequest $request): RedirectResponse
    {
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
        return view('gestor-users.show', [
            'gestorUser' => $gestorUser->load('subsystemAccounts.subsystem'),
        ]);
    }

    public function edit(GestorUser $gestorUser): View
    {
        return view('gestor-users.edit', compact('gestorUser'));
    }

    public function update(GestorUserRequest $request, GestorUser $gestorUser): RedirectResponse
    {
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
}