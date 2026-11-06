<?php

namespace App\Services\Import;

use App\Models\Subsystem;

/**
 * Índices de correlación sobre las cuentas remotas enumeradas.
 *
 * A partir de la lista bruta de cada subsistema se construyen tres
 * diccionarios por subsistema (CPF, login y email) sobre los que el
 * importador cruza a los usuarios de GLPI. Lo que quede sin consumir al
 * final son los huérfanos del reporte.
 */
final class IndicesImportacion
{
    /**
     * @param  array<string, Subsystem>  $subsistemas  slug => Subsystem
     * @param  array<string, list<RemoteAccount>>  $remotas
     * @param  array<string, array<string, int>>  $porCpf  slug => cpfLimpio => índice en $remotas[slug]
     * @param  array<string, array<string, list<int>>>  $porLogin
     * @param  array<string, array<string, list<int>>>  $porEmail
     * @param  array<string, list<string>>  $dominiosPorEmpresa  empresa (lowercase) => dominios
     */
    public function __construct(
        private readonly array $subsistemas,
        private readonly array $remotas,
        private readonly array $porCpf,
        private readonly array $porLogin,
        private readonly array $porEmail,
        private readonly array $dominiosPorEmpresa,
    ) {}

    /**
     * @param  array<string, Subsystem>  $subsistemas
     * @param  array<string, list<RemoteAccount>>  $enumeradas
     */
    public static function construir(array $subsistemas, array $enumeradas): self
    {
        $remotas = [];
        $porCpf = [];
        $porLogin = [];
        $porEmail = [];
        $dominiosPorEmpresa = [];

        foreach ($enumeradas as $slug => $cuentas) {
            $remotas[$slug] = $cuentas;

            foreach ($cuentas as $indice => $cuenta) {
                $cpf = $cuenta->cpfLimpio();

                if ($cpf !== null) {
                    $porCpf[$slug][$cpf] = $indice;
                }

                $login = $cuenta->loginNormalizado();

                if ($login !== null) {
                    $porLogin[$slug][$login][] = $indice;
                }

                $email = $cuenta->emailNormalizado();

                if ($email !== null) {
                    $porEmail[$slug][$email][] = $indice;
                }
            }
        }

        foreach ($subsistemas as $slug => $subsystem) {
            $config = $subsystem->api_config ?? [];

            // EntraID: cada tenant trae su dominio, con la clave = empresa.
            foreach ($config['accounts'] ?? [] as $empresaKey => $tenant) {
                if (is_array($tenant) && filled($tenant['dominio'] ?? null)) {
                    $dominiosPorEmpresa[strtolower((string) $empresaKey)][] = strtolower((string) $tenant['dominio']);
                }
            }

            if (filled($config['dominio'] ?? null)) {
                $dominio = strtolower((string) $config['dominio']);
                $empresaKey = self::empresaKeyDelDominio($dominio, $subsistemas) ?? $slug;

                $dominiosPorEmpresa[$empresaKey][] = $dominio;
            }
        }

        $dominiosPorEmpresa = array_map(
            static fn (array $dominios): array => array_values(array_unique($dominios)),
            $dominiosPorEmpresa,
        );

        return new self($subsistemas, $remotas, $porCpf, $porLogin, $porEmail, $dominiosPorEmpresa);
    }

    /**
     * @return array<string, Subsystem>
     */
    public function subsistemas(): array
    {
        return $this->subsistemas;
    }

    /**
     * @return list<RemoteAccount>
     */
    public function remotas(string $slug): array
    {
        return $this->remotas[$slug] ?? [];
    }

    /**
     * @return iterable<string, RemoteAccount> slug => cuenta
     */
    public function remotasTodas(): iterable
    {
        foreach ($this->remotas as $slug => $cuentas) {
            foreach ($cuentas as $cuenta) {
                yield $slug => $cuenta;
            }
        }
    }

    /**
     * @return array<string, list<string>> empresa (lowercase) => dominios
     */
    public function empresas(): array
    {
        return $this->dominiosPorEmpresa;
    }

    /**
     * Busca una cuenta correlacionable: CPF primero, luego login, luego email.
     *
     * Si varias cuentas remotas comparten login o email (particularidad
     * posible en plataformas con multiples empresas), se prefiere la primera
     * que coincida además por CPF.
     */
    public function buscar(string $slug, string $cpf, ?string $login, ?array $emails = null): ?RemoteAccount
    {
        // EntraID no correlaciona por CPF: el employeeId devuelto por Graph
        // no se usa como clave de correlación. La búsqueda se hace por username
        // (login) o email.
        $indice = $slug === 'entraid'
            ? null
            : ($this->porCpf[$slug][$cpf] ?? null);

        if ($indice !== null) {
            return $this->remotas($slug)[$indice] ?? null;
        }

        $loginNormalizado = $login === null ? null : strtolower(trim($login));

        if (filled($loginNormalizado) && isset($this->porLogin[$slug][$loginNormalizado])) {
            $encontrado = $this->primera($this->porLogin[$slug][$loginNormalizado], $slug, $cpf);

            if ($encontrado !== null) {
                return $encontrado;
            }
        }

        if ($emails === null) {
            return null;
        }

        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));

            if (isset($this->porEmail[$slug][$email])) {
                return $this->remotas($slug)[$this->porEmail[$slug][$email][0]] ?? null;
            }
        }

        return null;
    }

    /**
     * Intenta rescatar el CPF de un usuario de GLPI que no lo tiene: otra
     * plataforma con el mismo login o el mismo email y CPF conocido.
     */
    public function rescatarCpf(RemoteAccount $cuentaGlpi): ?string
    {
        $login = $cuentaGlpi->loginNormalizado();
        $email = $cuentaGlpi->emailNormalizado();

        if ($login === null && $email === null) {
            return null;
        }

        // Adagio no se enumera (no hay listado): el rescate cae en las
        // plataformas que sí tienen índice con CPF. Chatwoot no lo tiene.
        foreach (['entraid', 'sambaad'] as $slug) {
            if ($login !== null) {
                $cpf = $this->cpfDe($this->porLogin[$slug][$login] ?? [], $slug);

                if ($cpf !== null) {
                    return $cpf;
                }
            }

            if ($email !== null) {
                $cpf = $this->cpfDe($this->porEmail[$slug][$email] ?? [], $slug);

                if ($cpf !== null) {
                    return $cpf;
                }
            }
        }

        return null;
    }

    /**
     * Empresa (clave en minúsculas, p. ej. 'klios') a la que pertenece el
     * dominio corporativo, o null si no encaja en ninguna empresa declarada.
     *
     * La clave se resuelve primero contra los dominios declarados por los
     * subsistemas (Adagio por entidad, Email, Samba AD) y luego contra los
     * dominios registrados desde tenants con clave de empresa (EntraID).
     */
    public function empresaPorDominio(string $dominio): ?string
    {
        $empresaKey = self::empresaKeyDelDominio($dominio, $this->subsistemas);

        if ($empresaKey !== null) {
            return $empresaKey;
        }

        $dominioNormalizado = strtolower(trim($dominio));

        foreach ($this->dominiosPorEmpresa as $empresa => $dominios) {
            if (in_array($dominioNormalizado, $dominios, true)) {
                return strtolower((string) $empresa);
            }
        }

        return null;
    }

    /**
     * Clave de empresa (minúsculas) del dominio corporativo declarado por un
     * subsistema, o null si no se puede atribuir a ninguna empresa.
     *
     * @param  array<string, Subsystem>  $subsistemas
     */
    private static function empresaKeyDelDominio(string $dominio, array $subsistemas): ?string
    {
        $dominioNormalizado = strtolower(trim($dominio));

        foreach ($subsistemas as $subsystem) {
            $subDominio = strtolower(trim((string) ($subsystem->api_config['dominio'] ?? '')));

            if ($subDominio === '' || strcasecmp($subDominio, $dominioNormalizado) !== 0) {
                continue;
            }

            $empresaKey = self::empresaKeyDelSubsystem($subsystem);

            if ($empresaKey !== null) {
                return $empresaKey;
            }
        }

        return null;
    }

    /**
     * Clave de empresa del subsistema: el proveedor de identidad (Adagio)
     * resuelve la entidad (empresa) desde su configuración; el resto de
     * subsistemas no atribuye dominio a empresa explícitamente.
     */
    private static function empresaKeyDelSubsystem(Subsystem $subsystem): ?string
    {
        $entidad = strtolower(trim((string) ($subsystem->api_config['entidad'] ?? $subsystem->api_config['entidad_default'] ?? '')));

        if ($entidad === '') {
            return null;
        }

        return $entidad;
    }

    /**
     * @param  list<int>  $indices
     */
    private function cpfDe(array $indices, string $slug): ?string
    {
        foreach ($indices as $indice) {
            $cuenta = $this->remotas($slug)[$indice] ?? null;

            if ($cuenta?->cpfLimpio() !== null) {
                return $cuenta->cpfLimpio();
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $indices
     */
    private function primera(array $indices, string $slug, string $cpf): ?RemoteAccount
    {
        $cuentas = $this->remotas($slug);

        foreach ($indices as $indice) {
            $cuenta = $cuentas[$indice] ?? null;

            if ($cuenta === null) {
                continue;
            }

            // Coincidencia por login difícil de fiar sola: si hay varias con
            // el mismo login, la que esté lo refuerza sobre el CPF.
            if ($cuenta->cpfLimpio() !== null && $cuenta->cpfLimpio() !== $cpf) {
                continue;
            }

            return $cuenta;
        }

        return $cuentas[$indices[0] ?? ''] ?? null;
    }
}
