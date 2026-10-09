<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SecretsRotate extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'secrets:rotate {--key=app}';

    /**
     * The console command description.
     */
    protected $description = 'Rotate application secrets such as APP_KEY. Currently supports only the Laravel application key.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $key = $this->option('key');

        if ($key !== 'app') {
            $this->error('Only the Laravel APP_KEY rotation is supported at this time.');
            return 1;
        }

        $this->info('Rotating Laravel APP_KEY...');
        // Calls the built‑in key:generate command, which updates the .env file.
        Artisan::call('key:generate', ['--force' => true]);
        $output = Artisan::output();
        $this->line($output);
        $this->info('APP_KEY rotated. Remember to restart the application containers if running in Docker.');

        return 0;
    }
}
