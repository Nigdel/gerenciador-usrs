<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:reconcile')]
#[Description('Reconciliar cuentas de operaciones pendientes o en error')]
class AccountsReconcileCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Load all relevant user subsystem accounts
        $cuentas = \App\Models\UserSubsystemAccount::query()
            ->whereIn('estado', [
                \App\Enums\AccountStatus::Activo,
                \App\Enums\AccountStatus::Suspendido,
                \App\Enums\AccountStatus::Deshabilitado,
            ])
            ->with('subsystem')
            ->get();

        if ($cuentas->isEmpty()) {
            $this->components->info('No hay cuentas que conciliar.');
            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $discrepancias = [];
        $procesadas = 0;

        foreach ($cuentas as $cuenta) {
            $procesadas++;
            // Resolve the service class based on subsystem slug
            $serviceClass = match ($cuenta->subsystem->slug) {
                'email' => \App\Services\Subsystems\EmailService::class,
                'slack' => \App\Services\Subsystems\SlackService::class,
                'samba' => \App\Services\Subsystems\SambaAdService::class,
                'adagio' => \App\Services\Subsystems\AdagioService::class,
                'entra_id' => \App\Services\Subsystems\EntraIdService::class,
                'glpi' => \App\Services\Subsystems\GlpiService::class,
                'chatwoot' => \App\Services\Subsystems\ChatwootService::class,
                default => null,
            };

            if (! $serviceClass) {
                // Unknown subsystem – skip with a warning
                $this->components->warn("Subsistema desconocido: {$cuenta->subsystem->slug}");
                continue;
            }

            /** @var \App\Services\Subsystems\BaseSubsystemService $service */
            $service = app($serviceClass);

            try {
                $resultado = $service->getUserStatus($cuenta);
                $estadoRemoto = $resultado->status; // enum SubsystemUserStatus
            } catch (\Throwable $e) {
                $this->components->error("Error al obtener estado de {$cuenta->id}: {$e->getMessage()}");
                continue;
            }

            $estadoLocal = $cuenta->estado;
            if ($estadoRemoto->value !== $estadoLocal->value) {
                $discrepancias[] = [
                    'cuenta' => $cuenta,
                    'local' => $estadoLocal->value,
                    'remoto' => $estadoRemoto->value,
                ];

                if (! $dryRun) {
                    \App\Models\AccountDiscrepancy::updateOrCreate(
                        [
                            'user_subsystem_account_id' => $cuenta->id,
                            'subsystem_id' => $cuenta->subsystem_id,
                        ],
                        [
                            'estado_local' => $estadoLocal->value,
                            'estado_remoto' => $estadoRemoto->value,
                            'detected_at' => now(),
                        ]
                    );
                }
            }
        }

        $this->components->info("Se procesaron {$procesadas} cuentas.");
        $this->components->info("Se detectaron " . count($discrepancias) . " discrepancias.");

        if (! $dryRun && count($discrepancias) > 0) {
            foreach ($discrepancias as $d) {
                $this->components->twoColumnDetail(
                    "Cuenta {$d['cuenta']->id} ({$d['cuenta']->subsystem->slug})",
                    "Local: {$d['local']} → Remoto: {$d['remoto']}"
                );
            }
        }
    }
}
