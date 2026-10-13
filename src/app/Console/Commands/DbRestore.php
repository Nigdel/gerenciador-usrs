<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class DbRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:restore {file : The path to the encrypted backup file} {--dry-run : Display what would happen without executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Decrypt and restore a MySQL database backup';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $encryptedPath = $this->argument('file');

        if (! File::exists($encryptedPath)) {
            $this->error("Backup file not found: {$encryptedPath}");

            return Command::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run enabled. No restoration will be performed.');
            $this->info('Action: openssl enc -d -aes-256-cbc');
            $this->info('Action: mysql '.Config::get('database.connections.mysql.database'));

            return Command::SUCCESS;
        }

        // Safeguard: Confirmation prompt
        if (! $this->confirm('WARNING: This will overwrite the current database. Are you sure you want to proceed?', false)) {
            $this->info('Restoration cancelled.');

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

        $tempDecryptedPath = storage_path('app/temp_restore_'.uniqid().'.sql');

        $this->info('Decrypting backup file...');

        // 1. Decrypt with OpenSSL
        $decryptProcess = new Process([
            'openssl',
            'enc',
            '-d',
            '-aes-256-cbc',
            '-salt',
            '-pbkdf2',
            '-in', $encryptedPath,
            '-out', $tempDecryptedPath,
            '-pass', "pass:{$backupPassword}",
        ]);
        $decryptProcess->run();

        if (! $decryptProcess->isSuccessful()) {
            $this->error('Decryption failed: '.$decryptProcess->getErrorOutput());

            return Command::FAILURE;
        }

        $this->info('Decryption successful. Importing to database...');

        // 2. Import to MySQL
        // We pipe the file into mysql
        $importProcess = new Process([
            'mysql',
            '-h', $host,
            '-u', $username,
            "-p{$password}",
            $database,
        ]);
        $importProcess->setInput(File::get($tempDecryptedPath));
        $importProcess->run();

        if (! $importProcess->isSuccessful()) {
            $this->error('Database import failed: '.$importProcess->getErrorOutput());
            if (File::exists($tempDecryptedPath)) {
                File::delete($tempDecryptedPath);
            }

            return Command::FAILURE;
        }

        // 3. Cleanup
        File::delete($tempDecryptedPath);

        $this->info("Database successfully restored from {$encryptedPath}");

        return Command::SUCCESS;
    }
}
