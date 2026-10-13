<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DbBackup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup {--dry-run : Display what would happen without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an encrypted backup of the MySQL database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run enabled. No backup will be created.');
            $this->info('Action: mysqldump '.Config::get('database.connections.mysql.database'));
            $this->info('Action: openssl enc -aes-256-cbc');

            return Command::SUCCESS;
        }

        $database = Config::get('database.connections.mysql.database');
        $username = Config::get('database.connections.mysql.username');
        $password = Config::get('database.connections.mysql.password');
        $host = Config::get('database.connections.mysql.host', '127.0.0.1');
        $backupPassword = Config::get('database.backup_password');

        if (! $backupPassword) {
            $this->error('DB_BACKUP_PASSWORD is not defined in the .env file.');

            return Command::FAILURE;
        }

        $timestamp = now()->format('Y-m-d_H-i-s');
        $filename = "backup_{$database}_{$timestamp}.sql";
        $encryptedFilename = "{$filename}.enc";
        $tempPath = storage_path("app/temp_{$filename}");
        $finalPath = storage_path("app/backups/{$encryptedFilename}");

        if (! File::exists(storage_path('app/backups'))) {
            File::makeDirectory(storage_path('app/backups'), 0755, true);
        }

        $this->info("Starting backup of database: {$database}...");

        // 1. Create MySQL Dump
        // We use -p with password inside the command.
        // Warning: in some systems this might show in process list, but in Docker it's generally acceptable for these scripts.
        $dumpProcess = new Process([
            'mysqldump',
            '-h', $host,
            '-u', $username,
            "-p{$password}",
            $database,
        ]);
        // Redirect stdout to file
        $dumpProcess->setTty(false);
        $dumpProcess->run();
        File::put($tempPath, $dumpProcess->getOutput());

        if (! $dumpProcess->isSuccessful()) {
            $this->error('MySQL dump failed: '.$dumpProcess->getErrorOutput());

            return Command::FAILURE;
        }

        $this->info('Dump created. Encrypting...');

        // 2. Encrypt with OpenSSL
        // -pbkdf2 is required for modern openssl to avoid warnings and increase security
        $encryptProcess = new Process([
            'openssl',
            'enc',
            '-aes-256-cbc',
            '-salt',
            '-pbkdf2',
            '-in', $tempPath,
            '-out', $finalPath,
            '-pass', "pass:{$backupPassword}",
        ]);
        $encryptProcess->run();

        if (! $encryptProcess->isSuccessful()) {
            $this->error('Encryption failed: '.$encryptProcess->getErrorOutput());
            if (File::exists($tempPath)) {
                File::delete($tempPath);
            }

            return Command::FAILURE;
        }

        // 3. Cleanup temporary file
        File::delete($tempPath);

        $this->info("Backup successfully created and encrypted: {$finalPath}");

        // 4. Retention: Delete backups older than X days
        $retentionDays = Config::get('database.backup_retention_days', 30);
        $this->info("Cleaning up backups older than {$retentionDays} days...");

        $files = File::files(storage_path('app/backups'));
        $now = now();
        $count = 0;

        foreach ($files as $file) {
            if ($file->getMTime() < $now->subDays($retentionDays)->getTimestamp()) {
                File::delete($file->getPathname());
                $count++;
            }
        }

        $this->info("Removed {$count} old backup(s).");

        return Command::SUCCESS;
    }
}
