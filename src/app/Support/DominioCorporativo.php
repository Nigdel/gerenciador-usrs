<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Dominio de email corporativo derivado de la empresa del gestor.
 *
 * Es la fuente única para armar la dirección `usuario@dominio`: la empresa
 * "Klios" usa klios.com.br, "Federal" el dominio institucional federalst.com.br
 * y cualquier otra se normaliza a slug.com.br. Devolver null cuando no hay
 * empresa evita inventar el dominio literal "empresa.com.br".
 */
final class DominioCorporativo
{
    public static function para(?string $empresa): ?string
    {
        $slug = Str::slug((string) $empresa, '');

        if ($slug === '') {
            return null;
        }

        return $slug === 'federal' ? 'federalst.com.br' : $slug.'.com.br';
    }
}
