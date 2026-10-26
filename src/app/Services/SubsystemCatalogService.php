<?php

namespace App\Services;

use App\Models\Subsystem;

/**
 * Describe cada subsistema para las integraciones: qué configuración adicional
 * admite y qué valores no sensibles tiene hoy.
 *
 * `api_config` guarda tokens y credenciales cifrados, así que nunca se vuelca
 * entero: de cada driver se exponen solo las claves de configuración que no son
 * secretas (dominios, cuentas por empresa, cajas de entrada, etc.).
 */
class SubsystemCatalogService
{
    /**
     * Resumen de un subsistema para el listado público.
     *
     * @return array<string, mixed>
     */
    public function resumen(Subsystem $subsystem): array
    {
        return [
            'id' => $subsystem->id,
            'nombre' => $subsystem->nombre,
            'slug' => $subsystem->slug,
            'descripcion' => $subsystem->descripcion,
            'activo' => (bool) $subsystem->activo,
            'estado' => $this->estado($subsystem),
            'ultima_consulta_estado' => $subsystem->last_connection_test_at?->toIso8601String(),
            'ultima_consulta_correcta' => $subsystem->last_connection_test_success,
        ];
    }

    /**
     * Configuración adicional del subsistema: valores actuales no sensibles y
     * los campos que sus drivers aceptan al provisionar.
     *
     * @return array<string, mixed>
     */
    public function configuracionAdicional(Subsystem $subsystem): array
    {
        $config = is_array($subsystem->api_config) ? $subsystem->api_config : [];

        return match ($subsystem->slug) {
            'adagio' => [
                'entidad_predeterminada' => $config['entidad_default'] ?? null,
                'campos_soportados' => [
                    'entidad' => [
                        'tipo' => 'string',
                        'obligatorio' => false,
                        'descripcion' => 'Entidad de Adagio a la que pertenece la cuenta.',
                        'valores' => $this->empresas(),
                    ],
                ],
            ],
            'chatwoot' => [
                'cuentas_por_empresa' => $this->clavesDe($config['accounts'] ?? []),
                'inboxes_por_empresa' => $this->enterosDe($config['inboxes'] ?? []),
                'campos_soportados' => [
                    'teams' => [
                        'tipo' => 'array<integer>',
                        'obligatorio' => false,
                        'descripcion' => 'IDs de los equipos de Chatwoot a los que se añadirá al agente.',
                    ],
                    'inbox_ids' => [
                        'tipo' => 'array<integer>',
                        'obligatorio' => false,
                        'descripcion' => 'IDs de las cajas de entrada. Si se omite, se usa la configurada para la empresa.',
                    ],
                ],
            ],
            'email' => [
                'dominio' => $config['dominio'] ?? null,
                'campos_soportados' => (object) [],
            ],
            'entraid' => [
                'cuentas_por_empresa' => $this->clavesDe($config['accounts'] ?? []),
                'dominios_por_empresa' => $this->dominiosPorEmpresa($config['accounts'] ?? []),
                'campos_soportados' => (object) [],
            ],
            'sambaad' => [
                'dominio' => $config['dominio'] ?? null,
                'campos_soportados' => (object) [],
            ],
            'slack' => [
                'scim_habilitado' => (bool) ($config['scim_habilitado'] ?? false),
                'campos_soportados' => (object) [],
            ],
            default => [
                'campos_soportados' => (object) [],
            ],
        };
    }

    /**
     * Estado derivado del interruptor de activación y de la última prueba de
     * conexión. `sin_verificar` es distinto de `con_error`: el primero nunca se
     * comprobó y el segundo se comprobó y falló.
     */
    private function estado(Subsystem $subsystem): string
    {
        return match (true) {
            ! $subsystem->activo => 'inactivo',
            $subsystem->last_connection_test_at === null => 'sin_verificar',
            $subsystem->last_connection_test_success === true => 'operativo',
            default => 'con_error',
        };
    }

    /**
     * Empresas aceptadas en minúsculas, que es como las usan los drivers.
     *
     * @return array<int, string>
     */
    private function empresas(): array
    {
        return array_values(array_map(
            static fn ($empresa) => strtolower((string) $empresa),
            (array) config('subsystems.empresas', []),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function clavesDe(mixed $valor): array
    {
        return is_array($valor) ? array_values(array_map('strval', array_keys($valor))) : [];
    }

    /**
     * @return array<string, int>
     */
    private function enterosDe(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return collect($valor)
            ->mapWithKeys(fn ($item, $clave) => [(string) $clave => (int) $item])
            ->all();
    }

    /**
     * @return array<string, string|null>
     */
    private function dominiosPorEmpresa(mixed $cuentas): array
    {
        if (! is_array($cuentas)) {
            return [];
        }

        return collect($cuentas)
            ->mapWithKeys(fn ($configuracion, $empresa) => [
                (string) $empresa => is_array($configuracion) ? ($configuracion['dominio'] ?? null) : null,
            ])
            ->all();
    }
}
