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
        // Cache results for 60 seconds to avoid hammering the DB on every refresh
        $metrics = Cache::remember('dashboard_metrics', 60, function () {
            return [
                'users' => [
                    'total' => GestorUser::count(),
                    'by_status' => GestorUser::query()
                        ->selectRaw('estado, count(*) as total')
                        ->groupBy('estado')
                        ->get()
                        ->pluck('total', fn ($item) => $item->estado->value ?? $item->estado),
                ],
                'accounts' => [
                    'total' => UserSubsystemAccount::count(),
                    'by_status' => UserSubsystemAccount::query()
                        ->selectRaw('estado, count(*) as total')
                        ->groupBy('estado')
                        ->get()
                        ->pluck('total', fn ($item) => $item->estado->value ?? $item->estado),
                    'by_subsystem' => UserSubsystemAccount::query()
                        ->join('subsystems', 'user_subsystem_accounts.subsystem_id', '=', 'subsystems.id')
                        ->selectRaw('subsystems.nombre, count(*) as total')
                        ->groupBy('subsystems.nombre')
                        ->get()
                        ->pluck('total', 'nombre'),
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
                    ->select('nombre', 'last_connection_test_at', 'last_connection_test_success')
                    ->get()
                    ->map(fn ($s) => [
                        'nombre' => $s->nombre,
                        'last_test' => $s->last_connection_test_at?->diffForHumans(),
                        'success' => $s->last_connection_test_success,
                    ]),
            ];
        });

        return view('dashboard', [
            'metrics' => $metrics,
        ]);
    }
}
