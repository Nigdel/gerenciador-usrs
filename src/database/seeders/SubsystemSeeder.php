<?php

namespace Database\Seeders;

use App\Models\Subsystem;
use Illuminate\Database\Seeder;

class SubsystemSeeder extends Seeder
{
    public function run(): void
    {
        $subsistemas = [
            [
                'nombre' => 'Adagio',
                'slug' => 'adagio',
                'descripcion' => 'Sistema de gestión de personal, usado como proveedor de identidad',
                'api_url' => env('ADAGIO_API_URL', env('ADAGIO_BASE_URL')),
                'api_config' => [
                    'email' => env('ADAGIO_EMAIL'),
                    'password' => env('ADAGIO_PASSWORD'),
                    'token' => env('ADAGIO_API_TOKEN'),
                    'entidad_default' => env('ADAGIO_ENTIDAD_DEFAULT', 'klios'),
                ],
                'external_subsystem_id' => 'adagio-01',
                'es_proveedor_identidad' => true,
            ],
            [
                'nombre' => 'Glpi',
                'slug' => 'glpi',
                'descripcion' => 'Mesa de ayuda / inventario TI',
                'api_url' => env('GLPI_API_URL', env('GLPI_BASE_URL')),
                'api_config' => [
                    'token' => env('GLPI_API_TOKEN', env('GLPI_USER_TOKEN')),
                    'headers' => array_filter([
                        'App-Token' => env('GLPI_APP_TOKEN'),
                    ]),
                ],
                'external_subsystem_id' => 'glpi-01',
            ],
           [
                'nombre' => 'Chatwoot',
                'slug' => 'chatwoot',
                'descripcion' => 'Atención al cliente / chat (una instancia, 2 cuentas: Klios y Federal)',
                'api_url' => env('CHATWOOT_API_URL'),
                'api_config' => [
                    'token' => env('CHATWOOT_API_TOKEN'),
                    'auth_header' => 'api_access_token', // Chatwoot no usa Authorization: Bearer
                    'accounts' => [
                        'klios' => (int) env('CHATWOOT_ACCOUNT_ID_KLIOS', 1),
                        'federal' => (int) env('CHATWOOT_ACCOUNT_ID_FEDERAL', 2),
                    ],
                ],
                'external_subsystem_id' => 'chatwoot-01',
            ],
            [
                'nombre' => 'Email',
                'slug' => 'email',
                'descripcion' => 'Correo corporativo',
                'api_url' => env('EMAIL_API_URL'),
                'api_config' => ['token' => env('EMAIL_API_TOKEN'), 'dominio' => env('EMAIL_DOMINIO')],
                'external_subsystem_id' => 'email-01',
            ],
            [
                'nombre' => 'Slack',
                'slug' => 'slack',
                'descripcion' => 'Mensajería interna',
                'api_url' => env('SLACK_API_URL', 'https://slack.com/api'),
                'api_config' => ['token' => env('SLACK_API_TOKEN'), 'scim_habilitado' => false],
                'external_subsystem_id' => 'slack-01',
            ],
            [
                'nombre' => 'EntraId',
                'slug' => 'entraid',
                'descripcion' => 'Microsoft Entra ID (Azure AD) vía Graph API',
                'api_url' => env('ENTRAID_GRAPH_URL', 'https://graph.microsoft.com'),
                'api_config' => ['token' => env('ENTRAID_API_TOKEN'), 'dominio' => env('ENTRAID_DOMINIO')],
                'external_subsystem_id' => 'entraid-01',
            ],
            [
                'nombre' => 'SambaAd',
                'slug' => 'sambaad',
                'descripcion' => 'Directorio Samba AD',
                'api_url' => env('SAMBAAD_API_URL'),
                'api_config' => ['token' => env('SAMBAAD_API_TOKEN'), 'ou' => env('SAMBAAD_OU')],
                'external_subsystem_id' => 'sambaad-01',
            ],
        ];

        foreach ($subsistemas as $subsistema) {
            Subsystem::updateOrCreate(['slug' => $subsistema['slug']], $subsistema);
        }
    }
}
