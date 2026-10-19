<?php

namespace App\DTO;

/**
 * Resultado universal que debe devolver cualquier operación de un subsistema
 * (crear, suspender, deshabilitar, consultar estado...), sin importar qué
 * servicio concreto (Adagio, Glpi, EntraId, etc.) la haya ejecutado.
 */
class SubsystemOperationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $credencialUsuario = null,
        public readonly ?string $externalAccountId = null,
        public readonly ?string $estado = null,
        public readonly ?string $mensaje = null,
        public readonly array $raw = [],
        public readonly array $configuracionAdicional = [],
    ) {}

    public static function ok(
        ?string $credencialUsuario = null,
        ?string $externalAccountId = null,
        ?string $estado = null,
        ?string $mensaje = null,
        array $raw = [],
        array $configuracionAdicional = [],
    ): self {
        return new self(true, $credencialUsuario, $externalAccountId, $estado, $mensaje, $raw, $configuracionAdicional);
    }

    public static function fail(string $mensaje, array $raw = [], array $configuracionAdicional = []): self
    {
        return new self(false, mensaje: $mensaje, raw: $raw, configuracionAdicional: $configuracionAdicional);
    }
}
