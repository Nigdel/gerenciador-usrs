<?php

namespace App\Http\Controllers;

use App\Enums\GestorUserStatus;
use App\Enums\OperationStatus;
use App\Enums\SubsystemAccountStatus;
use App\Models\GestorUser;
use App\Models\ProvisioningOperation;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // Clave nueva (_v3) para descartar entradas viejas con otra forma
        $metrics = Cache::remember('dashboard_metrics_v3', 60, fn () => $this->buildMetrics());

        // Los gestores suspendidos se leen fuera de la caché: una suspensión
        // recién completada tiene que aparecer en el panel como pendiente de
        // reactivación sin esperar a que expire la métrica.
        $metrics['suspended_managers'] = $this->suspendedManagers();

        return view('dashboard', ['metrics' => $metrics]);
    }

    /**
     * Gestores con suspensión vigente: son los que hay que reactivar.
     *
     * @return array<int, array<string, string|null|bool>>
     */
    private function suspendedManagers(): array
    {
        return GestorUser::query()
            ->where('estado', GestorUserStatus::Suspendido)
            ->orderBy('suspendido_at')
            ->get(['id', 'nombre_completo', 'usuario', 'empresa', 'motivo_suspension_gestor', 'suspendido_at', 'fin_suspension_gestor'])
            ->map(fn (GestorUser $gestor) => [
                'nombre' => (string) $gestor->nombre_completo,
                'usuario' => (string) $gestor->usuario,
                'empresa' => (string) $gestor->empresa,
                'motivo' => $gestor->motivo_suspension_gestor,
                'suspendido_at' => $gestor->suspendido_at?->format('d/m/Y H:i'),
                'fin' => $gestor->fin_suspension_gestor?->format('d/m/Y'),
                'url' => route('gestor-users.show', $gestor),
                'can_reactivate' => Gate::allows('reactivateManager', $gestor),
                'reactivate_url' => route('gestor-users.reactivate-manager', $gestor),
            ])
            ->all();
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
    private function countByStatus(Builder $query): array
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
