<?php

namespace App\Services\Import;

/**
 * Mapa agente_id → inbox_ids / team_ids que la lectura en vivo de Chatwoot
 * produjo durante la enumeración de una cuenta (empresa).
 */
final class AccesosChatwoot
{
    /** @var array<string, list<int>> */
    public array $inboxIds = [];

    /** @var array<string, list<int>> */
    public array $teamIds = [];
}
