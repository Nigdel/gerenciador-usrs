<?php

namespace App\Http\Controllers;

use App\Enums\SubsystemAccountStatus;
use App\Http\Requests\UserSubsystemAccountRequest;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserSubsystemAccountController extends Controller
{
    public function index(GestorUser $gestorUser): View
    {
        $this->authorize('viewAny', UserSubsystemAccount::class);

        $gestorUser->load('subsystemAccounts.subsystem');

        return view('gestor-users.accounts.index', [
            'gestorUser' => $gestorUser,
        ]);
    }

    public function create(GestorUser $gestorUser): View
    {
        $this->authorize('create', UserSubsystemAccount::class);

        return view('gestor-users.accounts.create', [
            'gestorUser' => $gestorUser,
            'subsystems' => Subsystem::query()->activos()->orderBy('nombre')->get(),
            'statuses' => SubsystemAccountStatus::cases(),
        ]);
    }

    public function store(UserSubsystemAccountRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('create', UserSubsystemAccount::class);

        $data = $request->validated();
        $data['gestor_user_id'] = $gestorUser->id;
        $data['fecha_creacion'] ??= now();

        UserSubsystemAccount::create($data);

        return redirect()
            ->route('gestor-users.accounts.index', $gestorUser)
            ->with('success', 'Cuenta creada correctamente.');
    }

    public function edit(GestorUser $gestorUser, UserSubsystemAccount $userSubsystemAccount): View
    {
        abort_if($userSubsystemAccount->gestor_user_id !== $gestorUser->id, 404);

        $this->authorize('update', $userSubsystemAccount);

        return view('gestor-users.accounts.edit', [
            'gestorUser' => $gestorUser,
            'account' => $userSubsystemAccount,
            'subsystems' => Subsystem::query()->activos()->orderBy('nombre')->get(),
            'statuses' => SubsystemAccountStatus::cases(),
        ]);
    }

    public function update(UserSubsystemAccountRequest $request, GestorUser $gestorUser, UserSubsystemAccount $userSubsystemAccount): RedirectResponse
    {
        abort_if($userSubsystemAccount->gestor_user_id !== $gestorUser->id, 404);

        $this->authorize('update', $userSubsystemAccount);

        $userSubsystemAccount->update($request->validated());

        return redirect()
            ->route('gestor-users.accounts.index', $gestorUser)
            ->with('success', 'Cuenta actualizada correctamente.');
    }

    public function destroy(GestorUser $gestorUser, UserSubsystemAccount $userSubsystemAccount): RedirectResponse
    {
        abort_if($userSubsystemAccount->gestor_user_id !== $gestorUser->id, 404);

        $this->authorize('delete', $userSubsystemAccount);

        $userSubsystemAccount->delete();

        return redirect()
            ->route('gestor-users.accounts.index', $gestorUser)
            ->with('success', 'Cuenta eliminada correctamente.');
    }
}
