<?php

namespace App\Services\Import;

/**
 * Cuenta de usuario tal como se lee en un subsistema remoto (solo lectura).
 *
 * La fase de importación no usa los drivers de escritura: estos enums son
 * unidades de intercambio entre cada enumerador (uno por
 * subsistema) y el UserImportService, que las cruza entre sí para
 * agrupar cuentas bajo el mismo GestorUser.
 *
 * cpf (solo dígitos) es la clave de correlación primaria; login y email son
 * los fallbacks. estado usa los valores de SubsystemAccountStatus que el
 * mapeo de cada enumerador produce ('activo' | 'suspendido' |
 * 'deshabilitado' | 'borrado').
 */
final class RemoteAccount
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $configInicial  configuracion_adicional
     *                                                    sugerida por el enumerador (ej. la foto de
     *                                                    accesos de Chatwoot). Null = no aplica.
     */
    public function __construct(
        public readonly string $subsystemSlug,
        public readonly string $externalAccountId,
        public readonly ?string $login = null,
        public readonly ?string $email = null,
        public readonly ?string $cpf = null,
        public readonly string $estado = 'activo',
        public readonly ?string $nombreCompleto = null,
        public readonly array $meta = [],
        public readonly ?array $configInicial = null,
    ) {}

    public function conCpf(string $cpf): self
    {
        return new self(
            $this->subsystemSlug,
            $this->externalAccountId,
            $this->login,
            $this->email,
            $cpf,
            $this->estado,
            $this->nombreCompleto,
            $this->meta,
            $this->configInicial,
        );
    }

    /**
     * Huella estable de la cuenta dentro del snapshot enumerado: se usa para
     * marcar qué cuentas remotas terminaron asociadas (todo lo demás son
     * huérfanos) y para no asociarla dos veces.
     */
    public function fingerprint(): string
    {
        return $this->subsystemSlug.'#'.$this->externalAccountId.'#'.($this->cpfLimpio() ?? '-');
    }

    public function cpfLimpio(): ?string
    {
        $limpio = preg_replace('/\D/', '', (string) $this->cpf);

        return ($limpio === '' || $limpio === null) ? null : $limpio;
    }

    public function loginNormalizado(): ?string
    {
        $login = trim((string) $this->login);

        return $login === '' ? null : mb_strtolower($login);
    }

    public function emailNormalizado(): ?string
    {
        $email = strtolower(trim((string) $this->email));

        return ($email === '' || ! str_contains($email, '@')) ? null : $email;
    }
}
