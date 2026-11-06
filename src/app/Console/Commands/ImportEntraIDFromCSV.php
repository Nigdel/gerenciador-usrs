<?php

namespace App\Console\Commands;

use App\Models\GestorUser;
use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('app:import-entra-i-d-from-c-s-v')]
#[Description('Importa cuentas de EntraID desde un CSV: solo las que tienen userPrincipalName @klios.com.br y accountEnabled=true')]
class ImportEntraIDFromCSV extends Command
{
    public function handle(): int
    {
        $csvPaths = [
            '/var/www/storage/klios_entraid.csv',
            '/mnt/d/Users/nigdel/Downloads/exportUsers_2026-11-5.csv',
            '/tmp/exportUsers_2026-11-5.csv',
            '/tmp/klios_entraid.csv',
        ];

        $csvPath = null;
        foreach ($csvPaths as $path) {
            if (file_exists($path)) {
                $csvPath = $path;
                break;
            }
        }

        if ($csvPath === null) {
            $this->error('Archivo CSV no encontrado en rutas esperadas: '.implode(', ', $csvPaths));

            return 1;
        }

        $subsystem = Subsystem::where('slug', 'entraid')->firstOrFail();

        $this->info("Iniciando importación de EntraID desde CSV: {$csvPath}");
        $this->info('Filtrando: accountEnabled=true + userPrincipalName @klios.com.br');

        // Detectar delimitador: el archivo klios_entraid.csv usa TAB y VERDADEIRO/FALSO
        $handle = fopen($csvPath, 'r');
        if (! $handle) {
            $this->error('No se pudo abrir el CSV');

            return 1;
        }
        $first = fgets($handle);
        rewind($handle);
        $isTab = str_contains($first, "\t");
        $delim = $isTab ? "\t" : ',';
        fclose($handle);

        $this->info('Delimitador detectado: '.($isTab ? 'TAB' : 'COMA'));

        $handle = fopen($csvPath, 'r');
        // Header
        fgetcsv($handle, 0, $delim, '"', '\\');

        $creados = 0;
        $actualizados = 0;
        $omitidos = 0;
        $total = 0;

        while (($datos = fgetcsv($handle, 0, $delim, '"', '\\')) !== false) {
            $total++;
            if (count($datos) < 6) {
                $omitidos++;

                continue;
            }
            // Limpiar \r y trim
            $datos = array_map(fn ($v) => is_string($v) ? trim(rtrim($v, "\r")) : $v, $datos);

            $id = $datos[0] ?? '';
            $displayName = $datos[1] ?? '';
            $upn = trim($datos[2] ?? '');
            $accountEnabled = trim($datos[5] ?? '');
            $empresa = trim($datos[4] ?? '');
            if ($empresa === '') {
                $empresa = 'klios';
            }

            $isEnabled = in_array(strtolower($accountEnabled), ['true', 'verdadero', 'verdadeiro', '1', 'yes'], true);
            if (! $isEnabled || ! preg_match('/@klios\.com\.br$/', $upn)) {
                $omitidos++;

                continue;
            }

            $email = $upn;
            $username = preg_replace('/@klios\.com\.br$/', '', $upn);

            // Solo importa si ya tiene gestor user, sino no (según requisito)
            $gestor = GestorUser::where('email_personal', $email)->first();
            if (! $gestor) {
                $omitidos++;
                $this->warn("Omitido (sin gestor): {$email}");

                continue;
            }

            $existente = UserSubsystemAccount::query()
                ->where('gestor_user_id', $gestor->getKey())
                ->where('subsystem_id', $subsystem->getKey())
                ->first();

            $meta = [
                'upn' => $upn,
                'importado_desde' => 'csv',
                'display_name' => $displayName,
                'csv_id' => $id,
            ];

            if ($existente !== null) {
                $existente->credencial_usuario = $username;
                $existente->external_account_id = $id;
                $existente->estado = 'activo';
                $existente->meta = array_merge($existente->meta ?? [], $meta);
                $existente->save();
                $actualizados++;
            } else {
                try {
                    UserSubsystemAccount::create([
                        'gestor_user_id' => $gestor->getKey(),
                        'subsystem_id' => $subsystem->getKey(),
                        'credencial_usuario' => $username,
                        'external_account_id' => $id,
                        'fecha_creacion' => now(),
                        'estado' => 'activo',
                        'meta' => $meta,
                    ]);
                    $actualizados++;
                } catch (\Throwable $e) {
                    Log::error("Error creando cuenta EntraID para {$username}: ".$e->getMessage());
                    $this->error("Error creando cuenta para {$username}: ".$e->getMessage());
                    $omitidos++;
                }
            }
        }

        fclose($handle);

        $this->info('=== Resumen importación CSV EntraID ===');
        $this->info("Total líneas procesadas: {$total}");
        $this->info("Actualizados/creados: {$actualizados}");
        $this->info("Omitidos (no @klios.com.br / no VERDADEIRO / sin gestor): {$omitidos}");

        return 0;
    }
}
