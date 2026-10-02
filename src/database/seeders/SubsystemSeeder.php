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
                    'url' => env('ADAGIO_WEB_URL', env('ADAGIO_API_URL', env('ADAGIO_BASE_URL'))),
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
                    'url' => env('GLPI_WEB_URL', env('GLPI_API_URL', env('GLPI_BASE_URL'))),
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
                    'url' => env('CHATWOOT_WEB_URL', env('CHATWOOT_API_URL')),
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
                'api_config' => [
                    'url' => env('EMAIL_WEB_URL', env('EMAIL_API_URL')),
                    'token' => env('EMAIL_API_TOKEN'),
                    'dominio' => env('EMAIL_DOMINIO'),
                ],
                'external_subsystem_id' => 'email-01',
            ],
            [
                'nombre' => 'Slack',
                'slug' => 'slack',
                'descripcion' => 'Mensajería interna',
                'api_url' => env('SLACK_API_URL', 'https://slack.com/api'),
                'api_config' => [
                    'url' => env('SLACK_WEB_URL', env('SLACK_API_URL', 'https://slack.com/api')),
                    'token' => env('SLACK_API_TOKEN'),
                    'scim_habilitado' => false,
                ],
                'external_subsystem_id' => 'slack-01',
            ],
            // Entra ID: una instancia con varias app registrations, una por empresa.
            [
                'nombre' => 'EntraId',
                'slug' => 'entraid',
                'descripcion' => 'Microsoft Entra ID (Azure AD) multi-tenant vía Graph API',
                'api_url' => env('ENTRAID_GRAPH_URL', 'https://graph.microsoft.com'),
                'api_config' => [
                    'url' => env('ENTRAID_WEB_URL', env('ENTRAID_GRAPH_URL', 'https://graph.microsoft.com')),
                    'accounts' => [
                        'klios' => [
                            'tenant_id' => env('ENTRAID_KLIOS_TENANT_ID'),
                            'client_id' => env('ENTRAID_KLIOS_CLIENT_ID'),
                            'client_secret' => env('ENTRAID_KLIOS_CLIENT_SECRET'),
                            'dominio' => env('ENTRAID_KLIOS_DOMINIO', 'klios.com.br'),
                        ],
                        'federal' => [
                            'tenant_id' => env('ENTRAID_FEDERALST_TENANT_ID'),
                            'client_id' => env('ENTRAID_FEDERALST_CLIENT_ID'),
                            'client_secret' => env('ENTRAID_FEDERALST_CLIENT_SECRET'),
                            'dominio' => env('ENTRAID_FEDERALST_DOMINIO', 'federalst.com.br'),
                        ],
                    ],
                    'state_confirmation_attempts' => 5,
                    'state_confirmation_delay_ms' => 500,
                ],
                'external_subsystem_id' => 'entraid-01',
            ],
            [
                'nombre' => 'Samba AD',
                'slug' => 'sambaad',
                'descripcion' => 'Directorio Activo Samba AD para gestión de usuarios',
                'api_url' => env('AD_HOST', 'dc1.klios.br'),
                'api_config' => [
                    'url' => env('AD_WEB_URL', env('AD_HOST', 'dc1.klios.br')),
                    'host' => env('AD_HOST', 'dc1.klios.br'),
                    'port' => (int) env('AD_PORT', 636),
                    'base_dn' => env('AD_BASE_DN', 'DC=klios,DC=br'),
                    'bind_dn' => env('AD_BIND_DN', 'CN=svc-gestor,CN=Users,DC=klios,DC=br'),
                    'bind_password' => env('AD_BIND_PASSWORD'),
                    'users_ou' => env('AD_USERS_OU', 'OU=Usuarios,DC=klios,DC=br'),
                    'use_ldaps' => (bool) env('AD_USE_LDAPS', true),
                    'tls_verify' => (bool) env('AD_TLS_VERIFY', true),
                    'ca_cert_path' => env('AD_CA_CERT_PATH'),
                    'dominio' => env('AD_DOMINIO', 'klios.br'),
                ],
                'external_subsystem_id' => 'sambaad-01',
            ],
        ];

        foreach ($subsistemas as $subsistema) {
            Subsystem::updateOrCreate(['slug' => $subsistema['slug']], $subsistema);
        }

        $principal = Subsystem::where('slug', 'entraid')->firstOrFail();

        Subsystem::whereIn('slug', ['entraid-klios', 'entraid-federalst'])
            ->where('id', '<>', $principal->id)
            ->each(function (Subsystem $legacy) use ($principal): void {
                $legacy->accounts()->update(['subsystem_id' => $principal->id]);
                $legacy->delete();
            });
    }
}