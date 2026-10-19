<?php

namespace App\Http\Controllers;

use App\Enums\OperationType;
use App\Enums\SubsystemAccountStatus;
use App\Http\Requests\StoreManyUserSubsystemAccountsRequest;
use App\Http\Requests\UserSubsystemAccountRequest;
use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\ProvisioningOperationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UserSubsystemAccountController extends Controller
{
    public function __construct(
        private readonly ProvisioningOperationService $operationService,
    ) {}

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
        $this->authorize('createAccount', $gestorUser);

        return view('gestor-users.accounts.create', [
            'gestorUser' => $gestorUser,
            'subsystems' => Subsystem::query()->activos()->orderBy('nombre')->get(),
            'statuses' => SubsystemAccountStatus::cases(),
        ]);
    }

    public function store(UserSubsystemAccountRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('create', UserSubsystemAccount::class);
        $this->authorize('createAccount', $gestorUser);

        $data = $request->validated();
        $data['gestor_user_id'] = $gestorUser->id;
        $data['fecha_creacion'] ??= now();

        UserSubsystemAccount::create($data);

        return redirect()
            ->route('gestor-users.accounts.index', $gestorUser)
            ->with('success', 'Cuenta creada correctamente.');
    }

    /**
     * Alta de varias cuentas a la vez desde la ficha del gestor.
     *
     * Reutiliza el mismo camino que el alta inicial: se encola una operación
     * de tipo Alta por cada subsistema y el job es el que crea la cuenta, fija
     * la credencial a partir de los datos del gestor y guarda el id externo y
     * el estado que devuelve el subsistema.
     */
    public function storeMany(StoreManyUserSubsystemAccountsRequest $request, GestorUser $gestorUser): RedirectResponse
    {
        $this->authorize('create', UserSubsystemAccount::class);
        $this->authorize('createAccount', $gestorUser);

        $data = $request->validated();

        $subsistemas = Subsystem::query()
            ->whereIn('id', $data['subsystem_ids'])
            ->get();

        if ($subsistemas->isEmpty()) {
            return redirect()
                ->route('gestor-users.show', $gestorUser)
                ->with('error', 'No hay subsistemas activos disponibles para crear las cuentas.');
        }

        $datos = [
            'cpf' => $gestorUser->cpf,
            'nombre_completo' => $gestorUser->nombre_completo,
            'email_personal' => $gestorUser->email_personal,
            'telefono_personal' => $gestorUser->telefono_personal,
            'telefono_trabajo' => $gestorUser->telefono_trabajo,
            'direccion_particular' => $gestorUser->direccion_particular,
            'empresa' => $gestorUser->empresa,
            'usuario' => $gestorUser->usuario,
        ];

        if (! empty($data['password_patron'])) {
            $datos['password_general'] = $data['password_patron'];
        }

        if (! empty($data['subsystem_config'])) {
            $datos['subsystem_config'] = $data['subsystem_config'];
        }

        $operacion = $this->operationService->describir(
            OperationType::Alta,
            $gestorUser,
            $subsistemas,
            $datos,
        );

        $this->operationService->despachar($operacion);

        return redirect()
            ->route('gestor-users.show', $gestorUser)
            ->with('success', sprintf(
                '%s para %d subsistema%s. El resultado aparecerá en esta misma ficha.',
                OperationType::Alta->aviso(),
                $subsistemas->count(),
                $subsistemas->count() === 1 ? '' : 's',
            ));
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
