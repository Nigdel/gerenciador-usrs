<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProvisioningOperationListRequest;
use App\Models\ProvisioningOperation;
use Illuminate\View\View;

class ProvisioningOperationController extends Controller
{
    public function index(ProvisioningOperationListRequest $request): View
    {
        $this->authorize('viewAny', ProvisioningOperation::class);

        $query = ProvisioningOperation::query()
            ->with(['usuario', 'actor'])
            ->when($request->estado(), fn ($q, $estado) => $q->where('estado', $estado))
            ->when($request->tipo(), fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->when($request->usuario(), function ($q, $userQuery) {
                $q->whereHas('usuario', function ($u) use ($userQuery) {
                    $u->where('nombre_completo', 'like', "%{$userQuery}%")
                        ->orWhere('cpf', 'like', "%{$userQuery}%")
                        ->orWhere('usuario', 'like', "%{$userQuery}%");
                });
            })
            ->when($request->fechaInicio(), fn ($q, $start) => $q->whereDate('iniciada_at', '>=', $start))
            ->when($request->fechaFim(), fn ($q, $end) => $q->whereDate('iniciada_at', '<=', $end))
            ->orderBy('iniciada_at', 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('operaciones.index', [
            'operaciones' => $query,
            'filtros' => $request->only(['q', 'estado', 'tipo', 'usuario', 'fecha_inicio', 'fecha_fim']),
        ]);
    }

    public function show(ProvisioningOperation $operacione): View
    {
        // Route::resource nombra el parámetro «operacione» (de «operaciones»),
        // no «provisioning_operation». La resolución implícita empareja por
        // nombre, así que sin esto el modelo llegaba vacío a la vista.
        $this->authorize('view', $operacione);

        return view('operaciones.show', [
            'operacion' => $operacione->load(['usuario', 'actor', 'cuentas']),
        ]);
    }
}
