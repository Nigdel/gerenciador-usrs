<?php

namespace App\Services\Termos;

use App\Models\Termo;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;

/**
 * Genera el PDF del termo a partir de una plantilla Blade.
 *
 * El plugin de GLPI llenaba un DOCX y lo convertía con LibreOffice. Aquí no
 * hace falta un binario externo ni un formato intermedio: el mismo documento
 * se escribe en HTML y Dompdf lo pasa a PDF. La ventaja práctica es que el
 * contenedor no crece con LibreOffice y que el texto legal vive en el repo,
 * versionado y revisable.
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
        $html = View::make('termos.pdf', [
            'termo' => $termo,
            'empresa' => $termo->seccion('empresa'),
            'usuario' => $termo->seccion('usuario'),
            'dispositivo' => $termo->seccion('dispositivo'),
            'logo' => $this->logoDataUri($termo),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        // Dompdf resuelve las rutas relativas de las imágenes contra este
        // directorio. Con isRemoteEnabled=false no sale a la red: solo lee
        // archivos locales.
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $ruta = $this->rutaGenerado($termo);

        if (! Storage::disk('local')->put($ruta, $dompdf->output())) {
            throw new RuntimeException('No se pudo guardar el PDF del termo.');
        }

        return $ruta;
    }

    public function rutaGenerado(Termo $termo): string
    {
        return $this->directorio($termo)."/termo-{$termo->id}.pdf";
    }

    /**
     * El logo de la empresa, embebido como data URI.
     *
     * Embeberlo evita depender del chroot de Dompdf y de que la ruta del
     * archivo exista en el momento de renderizar. Si no hay logo configurado
     * o el archivo falta, el PDF se genera igual: un termo sin logo es un
     * problema estético, no un motivo para impedir la entrega de un equipo.
     */
    private function logoDataUri(Termo $termo): ?string
    {
        $ruta = $termo->valor('empresa', 'logo');

        if ($ruta === null) {
            return null;
        }

        $absoluta = public_path($ruta);

        if (! is_file($absoluta)) {
            return null;
        }

        $contenido = file_get_contents($absoluta);

        if ($contenido === false) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contenido);
    }

    private function directorio(Termo $termo): string
    {
        return trim((string) config('termos.directorio', 'termos'), '/')
            ."/{$termo->gestor_user_id}";
    }
}
