<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\GlpiService;
use RuntimeException;

/**
 * Lista los usuarios de GLPI por la API REST.
 *
 * Extiende GlpiService para reutilizar la sesión (initSession + Session-Token
 * cacheado): es la misma autenticación que la de escritura. La enumeración
 * nunca escribe: solo lee listado y detalles.
 *
 * GLPI no deja filtrar activos/inactivos de forma fiable en el endpoint de
 * listado, así que se leen todos y el estado (is_deleted / is_active) se lee
 * del detalle de cada usuario. El CPF vive en el campo comment como
 * "cpf: <digitos>", la forma en la que createUser() del driver lo escribe.
 */
class GlpiRemoteAccountEnumerator extends GlpiService implements RemoteAccountEnumerator
{
    private const TAMANNO_PAGINA = 100;

    public function slug(): string
    {
        return 'glpi';
    }

    /**
     * Extrae el CPF del campo comment de GLPI: "cpf: 060.611.235-90" →
     * "06061123590" (solo dígitos). Acepta CPF con puntos/guiones de
     * formato; antes se cortaba en el primer número y dejaba un CPF truncado.
     */
    public function cpfDelComment(string $comment): ?string
    {
        if (preg_match('/cpf[^0-9]*([\d.\-]+)/i', $comment, $coincidencias) !== 1) {
            return null;
        }

        $cpf = preg_replace('/\D/', '', $coincidencias[1]);

        return ($cpf === '' || $cpf === null) ? null : $cpf;
    }

    public function enumerar(Subsystem $subsystem): array
    {
        $cuentas = [];

        foreach ($this->listarIds($subsystem) as $id) {
            $detalle = $this->http($subsystem)->get('/User/'.$id);

            if ($detalle->failed()) {
                $this->log('Import GLPI: no se pudo leer el detalle del usuario; se omite', ['id' => $id]);

                continue;
            }

            if ((int) $detalle->json('is_deleted') === 1) {
                $estado = 'borrado';
            } elseif (! (bool) $detalle->json('is_active')) {
                $estado = 'deshabilitado';
            } else {
                $estado = 'activo';
            }

            $cuentas[] = new RemoteAccount(
                subsystemSlug: $this->slug(),
                externalAccountId: (string) $id,
                login: $detalle->json('name'),
                email: $this->primerEmail($detalle->json() ?? []),
                cpf: $this->cpfDelComment((string) ($detalle->json('comment') ?? '')),
                estado: $estado,
                nombreCompleto: trim((string) $detalle->json('realname').' '.(string) $detalle->json('firstname')),
                meta: $detalle->json() ?? [],
            );
        }

        return $cuentas;
    }

    /**
     * Ids de usuarios, paginado hasta agotar la lista.
     *
     * @return list<int>
     */
    private function listarIds(Subsystem $subsystem): array
    {
        $ids = [];
        $pagina = 0;

        do {
            $desde = $pagina * self::TAMANNO_PAGINA;
            $hasta = $desde + self::TAMANNO_PAGINA - 1;

            // Al final de la lista (p. ej. range 300-399 con 350 usuarios) GLPI
            // devuelve 204 sin cuerpo: lista agotada, no error.
            $response = $this->http($subsystem)->get('/User', ['range' => "{$desde}-{$hasta}"]);

            if ($response->status() === 204) {
                break;
            }

            if ($response->failed()) {
                throw new RuntimeException('No se pudo listar los usuarios de GLPI (HTTP '.$response->status().').');
            }

            $lote = $response->json();
            $lote = is_array($lote) ? $lote : [];

            foreach ($lote as $item) {
                if (is_array($item) && isset($item['id'])) {
                    $ids[] = (int) $item['id'];
                }
            }

            $pagina++;

            // Cota de seguridad: el corte evita un loop infinito si GLPI
            // respondiera siempre lo mismo.
            if ($pagina > 200) {
                throw new RuntimeException('GLPI devolvió más de 200 páginas; se aborta la enumeración.');
            }
        } while (count($lote) === self::TAMANNO_PAGINA);

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function primerEmail(array $json): ?string
    {
        $emails = $json['_useremails'] ?? $json['emails'] ?? null;

        if (is_array($emails) && $emails !== []) {
            $primero = reset($emails);

            // GLPI anida cada email como [0 => ['email' => ...]]
            return is_array($primero) ? (string) reset($primero) : (string) $primero;
        }

        if (is_string($json['email'] ?? null)) {
            return $json['email'];
        }

        return null;
    }
}
