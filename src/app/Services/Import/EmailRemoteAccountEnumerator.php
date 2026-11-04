<?php

namespace App\Services\Import;

use App\Models\Subsystem;
use App\Services\Subsystems\EmailService;
use RuntimeException;

/**
 * Enumera las casillas de correo del servicio de email por API REST.
 *
 * Extiende EmailService para reutilizar el cliente HTTP con la
 * autenticación del subsistema. No se escribe nada: solo lista.
 *
 * La dirección del buzón **es** la identidad de la cuenta (igual que en
 * createUser): el login = parte local de la dirección y el CPF no forma
 * parte del modelo de este driver (la correlación la hace la fuente de
 * existencia, GLPI).
 */
class EmailRemoteAccountEnumerator extends EmailService implements RemoteAccountEnumerator
{
    public function slug(): string
    {
        return 'email';
    }

    public function enumerar(Subsystem $subsystem): array
    {
        $response = $this->http($subsystem)->get('/mailboxes', ['limit' => 200]);

        if ($response->failed()) {
            throw new RuntimeException('No se pudo listar las casillas de correo del servicio de email (HTTP '.$response->status().').');
        }

        $mailboxes = $response->json();
        $mailboxes = is_array($mailboxes) ? ($mailboxes['data'] ?? $mailboxes['Resources'] ?? $mailboxes) : [];

        $cuentas = [];

        foreach ($mailboxes as $mailbox) {
            if (! is_array($mailbox)) {
                continue;
            }

            $direccion = $mailbox['address'] ?? $mailbox['email'] ?? null;

            if (! is_string($direccion) || blank($direccion)) {
                continue;
            }

            $identificador = (string) ($mailbox['id'] ?? $direccion);

            $cuentas[] = new RemoteAccount(
                subsystemSlug: $this->slug(),
                externalAccountId: $identificador,
                login: strstr(strtolower($direccion), '@', true) ?: strtolower($direccion),
                email: $direccion,
                cpf: null,
                estado: $mailbox['active'] ? 'activo' : 'deshabilitado',
                meta: $mailbox,
            );
        }

        return $cuentas;
    }
}
