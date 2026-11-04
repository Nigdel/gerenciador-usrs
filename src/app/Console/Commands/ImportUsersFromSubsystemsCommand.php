<?php

namespace App\Console\Commands;

use App\Services\Import\UserImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:import-remote {--dry-run} {--incluir-inactivos-glpi} {--subsystem=*} {--outdir=}')]
#[Description('Importa cuentas existentes de GLPI/EntraID/SambaAD/Chatwoot/Email/Slack creando un gestor por usuario activo de GLPI y asociándole sus cuentas remotas')]
class ImportUsersFromSubsystemsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(UserImportService $importador): int
    {
        $this->components->info($this->option('dry-run')
            ? 'Importación remota en modo DRY-RUN (no escribe en la base de datos).'
            : 'Importación remota de cuentas.');

        $opciones = [
            'dry_run' => $this->option('dry-run'),
            'incluir_inactivos_glpi' => $this->option('incluir-inactivos-glpi'),
            'subsistemas' => array_filter((array) $this->option('subsystem')),
        ];

        $reporte = $importador->importar($opciones);

        // Resumen en pantalla.
        $this->newLine();
        $this->components->twoColumnDetail('Usuarios GLPI recorridos', (string) $reporte['resumen']['glpi_recorridos']);
        $this->components->twoColumnDetail('Gestores creados / actualizados', $reporte['resumen']['gestores_creados'].' / '.$reporte['resumen']['gestores_actualizados']);
        $this->components->twoColumnDetail('Cuentas creadas / actualizadas', $reporte['resumen']['cuentas_creadas'].' / '.$reporte['resumen']['cuentas_actualizadas']);
        $this->components->twoColumnDetail('Usuarios GLPI sin CPF', (string) $reporte['resumen']['glpi_sin_cpf']);

        foreach ($reporte['resumen']['remotas_por_subsistema'] as $slug => $cantidad) {
            $this->components->twoColumnDetail("Cuentas remotas listadas ({$slug})", (string) $cantidad);
        }

        $this->newLine();
        $this->components->info('Conflictos: '.count($reporte['conflictos']).' · Faltantes: '.count($reporte['faltantes']).' · Huérfanos: '.count($reporte['huerfanos']));

        $archivos = $this->escribirCsv($reporte, (string) ($this->option('outdir') ?? ''));

        foreach ($archivos as $archivo) {
            $this->components->twoColumnDetail(basename($archivo), $archivo);
        }

        if ($reporte['conflictos'] !== []) {
            $this->newLine();
            $this->table(['Tipo', 'Detalle', 'Mensaje'], $reporte['conflictos']);
        }

        return self::SUCCESS;
    }

    /**
     * Escribe los reportes como CSV debajo de storage/app/import/<timestamp>/.
     *
     * @param  array{conflictos: list<array<string, string>>, faltantes: list<array<string, string>>, huerfanos: list<array<string, ?string>>}  $reporte
     * @return list<string>
     */
    private function escribirCsv(array $reporte, string $outdirConfig): array
    {
        $outdir = rtrim($outdirConfig !== '' ? $outdirConfig : storage_path('app/import/'.now()->format('YmdHis')), '/');

        if (! is_dir($outdir) && ! @mkdir($outdir, 0775, true) && ! is_dir($outdir)) {
            $this->components->warn("No se pudo crear el directorio de reportes '{$outdir}'");

            return [];
        }

        $archivos = [];

        foreach (['conflictos', 'faltantes', 'huerfanos'] as $nombre) {
            $filas = $reporte[$nombre];

            if ($filas === []) {
                continue;
            }

            $ruta = $outdir.'/'.$nombre.'.csv';
            $manejador = fopen($ruta, 'w');

            if ($manejador === false) {
                $this->components->warn("No se pudo abrir '{$ruta}' para escritura");

                continue;
            }

            fputcsv($manejador, array_keys($filas[0]));

            foreach ($filas as $fila) {
                fputcsv($manejador, $fila);
            }

            fclose($manejador);
            $archivos[] = $ruta;
        }

        return $archivos;
    }
}
