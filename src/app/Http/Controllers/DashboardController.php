<?php

namespace App\Http\Controllers;

use App\Enums\OperationStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // Clave nueva (_v2) para descartar entradas viejas con otra forma
        $metrics = Cache::remember('dashboard_metrics_v2', 60, fn () => $this->buildMetrics());

        return view('dashboard', ['metrics' => $metrics]);
    }

    private function buildMetrics(): array
    {
        return [
            'users' => [
                'total' => GestorUser::count(),
                'by_status' => $this->countByStatus(GestorUser::query()),
            ],
            'accounts' => [
                'total' => UserSubsystemAccount::count(),
                'by_status' => $this->countByStatus(UserSubsystemAccount::query()),
                'by_subsystem' => UserSubsystemAccount::query()
                    ->toBase()
                    ->join('subsystems', 'user_subsystem_accounts.subsystem_id', '=', 'subsystems.id')
                    ->selectRaw('subsystems.nombre as nombre, count(*) as total')
                    ->groupBy('subsystems.nombre')
                    ->pluck('total', 'nombre')
                    ->map(fn ($n) => (int) $n)
                    ->all(),
            ],
            'operations' => [
                'failed' => ProvisioningOperation::query()
                    ->where('estado', OperationStatus::Fallida)
                    ->count(),
                'in_progress' => ProvisioningOperation::query()
                    ->where('estado', OperationStatus::EnCurso)
                    ->count(),
            ],
            'expiring_suspensions' => UserSubsystemAccount::query()
                ->whereNotNull('fin_suspension')
                ->where('fin_suspension', '<=', Carbon::now()->addDays(7))
                ->where('estado', SubsystemAccountStatus::Suspendido)
                ->count(),
            'connectivity' => Subsystem::query()
                ->get(['nombre', 'last_connection_test_at', 'last_connection_test_success'])
                ->map(fn ($s) => [
                    'nombre' => (string) $s->nombre,
                    'last_test' => $s->last_connection_test_at
                        ? Carbon::parse($s->last_connection_test_at)->diffForHumans()
                        : null,
                    'success' => (bool) $s->last_connection_test_success,
                ])
                ->all(),
        ];
    }

    /**
     * Cuenta registros agrupados por "estado" usando el valor crudo de la BD
     * (sin hidratar modelos ni enums), devolviendo siempre ['estado' => int].
     */
    private function countByStatus(\Illuminate\Database\Eloquent\Builder $query): array
    {
        return $query
            ->toBase()
            ->selectRaw('estado, count(*) as total')
            ->groupBy('estado')
            ->pluck('total', 'estado')
            ->map(fn ($n) => (int) $n)
            ->all();
    }
}