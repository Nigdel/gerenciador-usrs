<?php

namespace App\Console\Commands;

use App\Enums\SubsystemAccountStatus;
use App\Models\AccountDiscrepancy;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\AdagioService;
use App\Services\Subsystems\BaseSubsystemService;
use App\Services\Subsystems\ChatwootService;
use App\Services\Subsystems\EmailService;
use App\Services\Subsystems\EntraIdService;
use App\Services\Subsystems\GlpiService;
use App\Services\Subsystems\SambaAdService;
use App\Services\Subsystems\SlackService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('accounts:reconcile {--dry-run}')]
#[Description('Reconciliar cuentas de operaciones pendientes o en error')]
class AccountsReconcileCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Load all relevant user subsystem accounts
        $cuentas = UserSubsystemAccount::query()
            ->whereIn('estado', [
                SubsystemAccountStatus::Activo,
                SubsystemAccountStatus::Suspendido,
                SubsystemAccountStatus::Deshabilitado,
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
            $subsystem = $cuenta->subsystem;
            if (! $subsystem instanceof Subsystem) {
                $this->components->warn("La cuenta {$cuenta->id} no tiene un subsistema válido.");

                continue;
            }

            // Resolve the service class based on subsystem slug
            $serviceClass = match ($subsystem->slug) {
                'email' => EmailService::class,
                'slack' => SlackService::class,
                'samba' => SambaAdService::class,
                'adagio' => AdagioService::class,
                'entra_id' => EntraIdService::class,
                'glpi' => GlpiService::class,
                'chatwoot' => ChatwootService::class,
                default => null,
            };

            if (! $serviceClass) {
                // Unknown subsystem – skip with a warning
                $this->components->warn("Subsistema desconocido: {$subsystem->slug}");

                continue;
            }

            /** @var BaseSubsystemService $service */
            $service = app($serviceClass);

            try {
                $resultado = $service->getUserStatus($cuenta);
                if (! $resultado->success || $resultado->estado === null) {
                    $this->components->warn("No se pudo determinar el estado remoto de la cuenta {$cuenta->id}.");

                    continue;
                }
                $estadoRemoto = $resultado->estado;
            } catch (\Throwable $e) {
                $this->components->error("Error al obtener estado de {$cuenta->id}: {$e->getMessage()}");

                continue;
            }

            $estadoLocal = $cuenta->estado;
            if ($estadoRemoto !== $estadoLocal->value) {
                $discrepancias[] = [
                    'cuenta' => $cuenta,
                    'local' => $estadoLocal->value,
                    'remoto' => $estadoRemoto,
                    'subsystem' => $subsystem->slug,
                ];

                if (! $dryRun) {
                    AccountDiscrepancy::updateOrCreate(
                        [
                            'user_subsystem_account_id' => $cuenta->id,
                        ],
                        [
                            'subsystem' => $subsystem->slug,
                            'estado_local' => $estadoLocal->value,
                            'remote_estado' => $estadoRemoto,
                            'detectada_at' => now(),
                        ]
                    );
                }
            }
        }

        $this->components->info("Se procesaron {$procesadas} cuentas.");
        $this->components->info('Se detectaron '.count($discrepancias).' discrepancias.');

        if (! $dryRun && count($discrepancias) > 0) {
            foreach ($discrepancias as $d) {
                $this->components->twoColumnDetail(
                    "Cuenta {$d['cuenta']->id} ({$d['subsystem']})",
                    "Local: {$d['local']} → Remoto: {$d['remoto']}"
                );
            }
        }
    }
}
