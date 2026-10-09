<?php

namespace App\Console;

use App\Console\Commands\AccountsReconcileCommand;
use App\Console\Commands\ApiTokenCommand;
use App\Console\Commands\ApplyPendingSuspensionsCommand;
use App\Console\Commands\CreateAdminCommand;
use App\Console\Commands\ExpireStuckOperationsCommand;
use App\Console\Commands\PruneOperationSecretsCommand;
use App\Console\Commands\ReactivateExpiredAccountsCommand;
use App\Console\Commands\SecretsRotate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by the application.
     */
    protected $commands = [
        AccountsReconcileCommand::class,
        ApiTokenCommand::class,
        ApplyPendingSuspensionsCommand::class,
        CreateAdminCommand::class,
        ExpireStuckOperationsCommand::class,
        PruneOperationSecretsCommand::class,
        ReactivateExpiredAccountsCommand::class,
        SecretsRotate::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Existing schedules are defined in routes/console.php; keep them here if needed.
        // This kernel ensures the command is discoverable.
    }
}
