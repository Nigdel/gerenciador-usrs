<?php

namespace App\Enums;

/**
 * Abilities que un token de la API puede tener. Cada una se comprueba con
 * Gate::allows() y se limita a un endpoint concreto de routes/api.php.
 */
enum ApiAbility: string
{
    case Provisionar = 'usuarios:provisionar';
    case Suspender = 'usuarios:suspender';
    case Consultar = 'operaciones:consultar';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
