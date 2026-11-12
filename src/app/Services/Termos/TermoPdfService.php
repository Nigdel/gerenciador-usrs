<?php

namespace App\Services\Termos;

use App\Enums\TermoTipo;
use App\Models\Termo;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Genera el PDF del termo con el mismo mecanismo que el plugin de GLPI: llena
 * la plantilla DOCX y la convierte a PDF con LibreOffice CLI.
 *
 * El encabezado, el pie y el texto legal son los del DOCX original; no se
 * reconstruyen en HTML. Así el documento conserva exactamente la tabla del
 * encabezado, los logos y la paginación de la plantilla institucional.
 *
 * El PDF se guarda en el disco privado y nunca se publica: el controlador es
 * el que lo sirve después de autorizar la ficha.
 */
class TermoPdfService
{
    /**
     * Genera y guarda el PDF, y devuelve su ruta relativa al disco local.
     */
    public function generar(Termo $termo): string
    {
        $plantilla = (string) config('termos.plantilla');

        if (! str_starts_with($plantilla, '/')) {
            $plantilla = base_path($plantilla);
        }

        if (! is_file($plantilla)) {
            throw new RuntimeException("No se encontró la plantilla del termo: {$plantilla}");
        }

        $directorio = $this->directorioTrabajo();

        try {
            $procesador = new TemplateProcessor($plantilla);
            $this->rellenar($procesador, $termo);

            $fotos = $this->fotos($termo);

            if ($fotos !== []) {
                $collage = $this->collage($fotos, $directorio);

                if ($collage !== null) {
                    $procesador->setImageValue('Fotos_Aqui', [
                        'path' => $collage['ruta'],
                        'width' => $this->aCentimetros($collage['ancho']).'cm',
                        'height' => $this->aCentimetros($collage['alto']).'cm',
                        // El collage ya tiene la proporción exacta de la
                        // cuadrícula: se fuerza el tamaño para que LibreOffice
                        // no lo reescale al insertarlo.
                        'ratio' => 'f',
                    ]);
                } else {
                    $procesador->setValue('Fotos_Aqui', '');
                }
            } else {
                $procesador->setValue('Fotos_Aqui', '');
            }

            $docx = $directorio.'/termo.docx';
            $procesador->saveAs($docx);

            $pdf = $this->convertir($docx, $directorio);

            $ruta = $this->rutaGenerado($termo);
            $contenido = file_get_contents($pdf);

            if ($contenido === false || ! Storage::disk('local')->put($ruta, $contenido)) {
                throw new RuntimeException('No se pudo guardar el PDF del termo.');
            }

            return $ruta;
        } finally {
            // La conversión deja DOCX, PDF, perfil de LibreOffice y collage en
            // este directorio. Nada de eso debe sobrevivir a la petición.
            File::deleteDirectory($directorio);
        }
    }

    public function rutaGenerado(Termo $termo): string
    {
        return $this->directorio($termo)."/termo-{$termo->id}.pdf";
    }

    /**
     * Vuelca el snapshot del termo en los marcadores de la plantilla.
     */
    private function rellenar(TemplateProcessor $procesador, Termo $termo): void
    {
        $empresa = $termo->seccion('empresa');
        $usuario = $termo->seccion('usuario');
        $dispositivo = $termo->seccion('dispositivo');

        $fecha = $termo->generado_at?->format('d/m/Y') ?? now()->format('d/m/Y');
        $nombreEmpresa = $this->texto($empresa, 'nombre');
        $cnpj = $this->texto($empresa, 'cnpj');
        $nombreUsuario = $this->texto($usuario, 'nombre');
        $cpf = $this->texto($usuario, 'cpf');

        // Las claves del snapshot son datos de personas y equipos: pueden
        // llevar "&", "<" o comillas. PhpWord escribe el valor tal cual dentro
        // del XML del DOCX, así que hay que escaparlo.
        Settings::setOutputEscapingEnabled(true);

        $procesador->setValues([
            'titulo_documento' => $termo->tipo->tituloDocumento(),
            'introduccion' => $this->introduccion(
                $termo->tipo,
                $fecha,
                $nombreEmpresa,
                $cnpj,
                $nombreUsuario,
                $cpf,
            ),
            'fecha' => $fecha,
            'ciudad' => $this->texto($empresa, 'ciudad', 'Goiânia/GO'),
            'empresa' => $nombreEmpresa,
            'cnpj' => $cnpj,
            'nombre' => $nombreUsuario,
            'cpf' => $cpf,
            'modelo' => $this->texto($dispositivo, 'modelo'),
            'procesador' => $this->texto($dispositivo, 'procesador'),
            'color' => $this->texto($dispositivo, 'color'),
            'ram' => $this->texto($dispositivo, 'ram'),
            'almacenamiento' => $this->texto($dispositivo, 'almacenamiento'),
            'mac' => $this->texto($dispositivo, 'mac'),
            'imei' => $this->texto($dispositivo, 'imei'),
            'estado' => $this->texto($dispositivo, 'estado'),
        ]);
    }

    /**
     * La frase inicial cambia según el movimiento del equipo. El texto de la
     * entrega es literalmente el del DOCX; los otros dos siguen la misma
     * redacción que ya usaba el formulario.
     */
    private function introduccion(
        TermoTipo $tipo,
        string $fecha,
        string $empresa,
        string $cnpj,
        string $nombre,
        string $cpf,
    ): string {
        return match ($tipo) {
            TermoTipo::Devolucion => "Nesta data {$fecha}, o(a) {$nombre}, sob nº de CPF/MF: {$cpf}, devolve à empresa {$empresa}, inscrita sob nº de CNPJ/MF: {$cnpj}, os seguintes equipamentos, descrição e registros fotográficos:",
            TermoTipo::Transferencia => "Nesta data {$fecha}, a empresa {$empresa}, inscrita sob nº de CNPJ/MF: {$cnpj}, transfere para o(a) {$nombre}, sob nº de CPF/MF: {$cpf}, os seguintes equipamentos, descrição e registros fotográficos:",
            TermoTipo::Entrega => "Nesta data {$fecha}, a empresa {$empresa}, inscrito sob nº de CNPJ/MF: {$cnpj}, faz a entrega para o(a) {$nombre} sob nº de CPF/MF: {$cpf} dos seguintes equipamentos, descrição e registros fotográficos:",
        };
    }

    /**
     * Las fotos del medio, ya decodificadas a binario.
     *
     * @return list<string>
     */
    private function fotos(Termo $termo): array
    {
        $imagenes = $termo->seccion('imagenes');
        $resultado = [];

        foreach ($imagenes as $imagen) {
            if (! is_array($imagen)) {
                continue;
            }

            $mime = strtolower((string) ($imagen['mime'] ?? ''));
            $base64 = (string) ($imagen['base64'] ?? '');

            if ($base64 === '' || ! str_starts_with($mime, 'image/')) {
                continue;
            }

            $binario = base64_decode($base64, true);

            if ($binario === false) {
                continue;
            }

            $resultado[] = $binario;
        }

        return $resultado;
    }

    /**
     * Compone las fotos en una sola imagen con celdas de tamaño fijo.
     *
     * Se usa un collage y no una tabla de imágenes para que Word y LibreOffice
     * no tengan que decidir el tamaño de cada foto: la cuadrícula ya sale del
     * tamaño configurado y se inserta como una imagen de dimensiones exactas.
     *
     * @param  list<string>  $fotos
     * @return array{ruta: string, ancho: string, alto: string}|null
     */
    private function collage(array $fotos, string $directorio): ?array
    {
        $columnas = max(1, (int) config('termos.imagen_columnas', 3));
        $ancho = (string) config('termos.imagen_ancho', '5.1cm');
        $alto = (string) config('termos.imagen_alto', '3.5cm');
        $ajuste = in_array(config('termos.imagen_ajuste'), ['cover', 'contain'], true)
            ? (string) config('termos.imagen_ajuste')
            : 'cover';
        $dpi = max(72, (int) config('termos.imagen_dpi', 150));
        $filas = (int) ceil(count($fotos) / $columnas);

        $celdaAncho = max(1, (int) round($this->aCentimetros($ancho) / 2.54 * $dpi));
        $celdaAlto = max(1, (int) round($this->aCentimetros($alto) / 2.54 * $dpi));

        $lienzo = imagecreatetruecolor($celdaAncho * $columnas, $celdaAlto * $filas);

        if ($lienzo === false) {
            return null;
        }

        $blanco = imagecolorallocate($lienzo, 255, 255, 255);
        imagefill($lienzo, 0, 0, $blanco);

        $colocadas = 0;

        foreach ($fotos as $indice => $binario) {
            if (@getimagesizefromstring($binario) === false) {
                continue;
            }

            $origen = @imagecreatefromstring($binario);

            if ($origen === false) {
                continue;
            }

            $columna = $indice % $columnas;
            $fila = intdiv($indice, $columnas);

            $this->dibujarFoto(
                $lienzo,
                $origen,
                $columna * $celdaAncho,
                $fila * $celdaAlto,
                $celdaAncho,
                $celdaAlto,
                $ajuste,
            );

            $colocadas++;
        }

        if ($colocadas === 0) {
            return null;
        }

        $ruta = $directorio.'/fotos.jpg';

        if (! imagejpeg($lienzo, $ruta, 88)) {
            return null;
        }

        return [
            'ruta' => $ruta,
            'ancho' => $this->formatearCentimetros($this->aCentimetros($ancho) * $columnas),
            'alto' => $this->formatearCentimetros($this->aCentimetros($alto) * $filas),
        ];
    }

    /**
     * Dibuja una foto dentro de una celda fija, centrada.
     *
     * Con "cover" la foto cubre la celda y se recorta el sobrante; con
     * "contain" entra completa y deja margen blanco. En ningún caso se deforma.
     */
    private function dibujarFoto(
        \GdImage $lienzo,
        \GdImage $origen,
        int $x,
        int $y,
        int $ancho,
        int $alto,
        string $ajuste,
    ): void {
        $origenAncho = imagesx($origen);
        $origenAlto = imagesy($origen);

        if ($origenAncho < 1 || $origenAlto < 1) {
            return;
        }

        $escala = $ajuste === 'contain'
            ? min($ancho / $origenAncho, $alto / $origenAlto)
            : max($ancho / $origenAncho, $alto / $origenAlto);

        $destinoAncho = max(1, (int) round($origenAncho * $escala));
        $destinoAlto = max(1, (int) round($origenAlto * $escala));

        // La celda se pinta aparte para que el recorte de "cover" no invada
        // las celdas vecinas.
        $celda = imagecreatetruecolor($ancho, $alto);

        if ($celda === false) {
            return;
        }

        $blanco = imagecolorallocate($celda, 255, 255, 255);
        imagefill($celda, 0, 0, $blanco);

        imagecopyresampled(
            $celda,
            $origen,
            (int) (($ancho - $destinoAncho) / 2),
            (int) (($alto - $destinoAlto) / 2),
            0,
            0,
            $destinoAncho,
            $destinoAlto,
            $origenAncho,
            $origenAlto,
        );

        imagecopy($lienzo, $celda, $x, $y, 0, 0, $ancho, $alto);
    }

    /**
     * Convierte el DOCX a PDF con LibreOffice y devuelve la ruta del PDF.
     */
    private function convertir(string $docx, string $directorio): string
    {
        $binario = (string) config('termos.libreoffice_bin', '/usr/bin/libreoffice');

        if (! is_file($binario)) {
            throw new RuntimeException("LibreOffice no está instalado en {$binario}.");
        }

        $pdf = $directorio.'/termo.pdf';

        $proceso = new Process([
            $binario,
            '--headless',
            '--norestore',
            '-env:UserInstallation=file://'.$directorio.'/perfil',
            '--convert-to',
            'pdf',
            '--outdir',
            $directorio,
            $docx,
        ]);

        $proceso->setTimeout(120);
        $proceso->run();

        if (! $proceso->isSuccessful() || ! is_file($pdf)) {
            throw new RuntimeException(
                'No se pudo convertir el termo a PDF: '.trim($proceso->getErrorOutput().' '.$proceso->getOutput()),
            );
        }

        return $pdf;
    }

    /**
     * Un directorio temporal único por generación.
     *
     * Se usa el directorio temporal del sistema y no storage/: el DOCX, el PDF
     * intermedio y el perfil de LibreOffice son efímeros, y en desarrollo el
     * árbol de storage se monta desde el host con propietarios que php-fpm no
     * puede representar. /tmp siempre es escribible por www-data.
     */
    private function directorioTrabajo(): string
    {
        $directorio = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'termo-'.Str::uuid();

        if (! File::makeDirectory($directorio, 0700, true)) {
            throw new RuntimeException('No se pudo crear el directorio temporal del termo.');
        }

        return $directorio;
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function texto(array $datos, string $clave, string $defecto = ''): string
    {
        $valor = $datos[$clave] ?? null;

        return is_scalar($valor) ? (string) $valor : $defecto;
    }

    /**
     * Convierte una medida de la configuración a centímetros.
     */
    private function aCentimetros(string $medida): float
    {
        if (! preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*(cm|mm|in|pt|px)?\s*$/i', $medida, $coincidencias)) {
            return 5.1;
        }

        $valor = (float) $coincidencias[1];
        $unidad = strtolower($coincidencias[2] ?? 'cm');

        return match ($unidad) {
            'mm' => $valor / 10,
            'in' => $valor * 2.54,
            'pt' => $valor * 2.54 / 72,
            'px' => $valor * 2.54 / 96,
            default => $valor,
        };
    }

    private function formatearCentimetros(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.').'cm';
    }

    private function directorio(Termo $termo): string
    {
        return trim((string) config('termos.directorio', 'termos'), '/')
            ."/{$termo->gestor_user_id}";
    }
}
