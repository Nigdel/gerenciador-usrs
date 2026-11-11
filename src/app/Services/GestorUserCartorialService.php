<?php

namespace App\Services;

use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;

/**
 * Cartorial del gestor: su segunda constancia documental.
 *
 * El cartorial no es una vista más de los subsistemas: es el lugar donde queda
 * lo que se sabe de una persona —quién es, con qué cuenta fue dada de alta y,
 * más adelante, qué procesos se le abrieron y con qué documentos—. Por eso se
 * arma aquí y no en la vista: la vista solo pinta bloques, y cada bloque nuevo
 * es un método nuevo aquí.
 *
 * Los datos salen de lo que ya está guardado (la ficha del gestor y la cuenta
 * en Adagio con su respuesta original en `meta`), no de una llamada a Adagio:
 * esta página se abre en cada consulta y preguntar al exterior en cada visita
 * la haría depender de un sistema de terceros para pintar una tabla.
 */
class GestorUserCartorialService
{
    /**
     * Bloques del cartorial, en el orden en que se muestran.
     *
     * Un bloque es un grupo de campos con su propio título. La forma es fija
     * porque la vista la recorre igual para todos: añadir un bloque nuevo es
     * añadir una entrada a esta lista, no tocar la plantilla.
     *
     * @return list<array{clave: string, titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}>
     */
    public function bloques(GestorUser $gestorUser): array
    {
        return [
            $this->bloqueIdentificacion($gestorUser),
            $this->bloqueCuentaAdagio($gestorUser),
        ];
    }

    /**
     * Documentos del cartorial: el listado tabular.
     *
     * Es la segunda forma de sección del cartorial: donde los bloques son
     * campos sueltos, aquí hay filas de una tabla. Las columnas van agrupadas
     * tal como las define el sistema de origen ("Usuário Alteração Status" es
     * una sola columna), porque partirlas daría una lectura distinta de la que
     * tiene el operador delante. "Status" y "Cadastro" sí van separadas: la
     * segunda es la fecha de registro del documento.
     *
     * @return array{columnas: list<string>, filas: list<array<string, ?string>>}
     */
    public function documentos(): array
    {
        return [
            'columnas' => [
                'Documento',
                'Situação',
                'Vencimento',
                'Dias',
                'Usuário Alteração Status',
                'Data Alteração',
                'Status',
                'Cadastro',
            ],
            'filas' => $this->documentosFicticios(),
        ];
    }

    /**
     * Documentos de ejemplo.
     *
     * FICTICIOS a propósito: todavía no hay dónde persistirlos, así que se
     * inventan aquí y se sustituyen por la consulta real en cuanto exista el
     * almacenamiento. Las fechas son relativas a hoy para que el listado se
     * vea vivo, y los valores son fijos para que el mismo gestor muestre
     * siempre el mismo listado.
     *
     * @return list<array<string, ?string>>
     */
    private function documentosFicticios(): array
    {
        return [
            [
                'Documento' => 'CTPS',
                'Situação' => 'Aprovado',
                'Vencimento' => now()->addDays(120)->format('d/m/Y'),
                'Dias' => '120',
                'Usuário Alteração Status' => 'Marina Duarte',
                'Data Alteração' => now()->subDays(3)->format('d/m/Y'),
                'Status' => 'Ativo',
                'Cadastro' => now()->subDays(400)->format('d/m/Y'),
            ],
            [
                'Documento' => 'RG',
                'Situação' => 'Em análise',
                'Vencimento' => now()->addDays(45)->format('d/m/Y'),
                'Dias' => '45',
                'Usuário Alteração Status' => 'Rafael Nogueira',
                'Data Alteração' => now()->subDay()->format('d/m/Y'),
                'Status' => 'Pendente',
                'Cadastro' => now()->subDays(380)->format('d/m/Y'),
            ],
            [
                'Documento' => 'Comprovante de residência',
                'Situação' => 'Pendente',
                'Vencimento' => now()->addDays(15)->format('d/m/Y'),
                'Dias' => '15',
                'Usuário Alteração Status' => 'Marina Duarte',
                'Data Alteração' => now()->subDays(10)->format('d/m/Y'),
                'Status' => 'Pendente',
                'Cadastro' => now()->subDays(90)->format('d/m/Y'),
            ],
            [
                'Documento' => 'CNH',
                'Situação' => 'Vencido',
                'Vencimento' => now()->subDays(20)->format('d/m/Y'),
                'Dias' => '-20',
                'Usuário Alteração Status' => 'Rafael Nogueira',
                'Data Alteração' => now()->subDays(60)->format('d/m/Y'),
                'Status' => 'Vencido',
                'Cadastro' => now()->subDays(700)->format('d/m/Y'),
            ],
        ];
    }

    /**
     * Quién es la persona, con los datos que ya viven en su ficha.
     *
     * @return array{clave: string, titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function bloqueIdentificacion(GestorUser $gestorUser): array
    {
        return [
            'clave' => 'identificacion',
            'titulo' => 'Identificación',
            'descripcion' => 'Datos con los que está dada de alta la persona en el Gestor.',
            'campos' => [
                ['etiqueta' => 'Nombre', 'valor' => $gestorUser->nombre_completo],
                ['etiqueta' => 'Usuario', 'valor' => $gestorUser->usuario],
                ['etiqueta' => 'Empresa', 'valor' => $gestorUser->empresa],
                ['etiqueta' => 'CPF', 'valor' => $gestorUser->cpf],
                ['etiqueta' => 'E-mail personal', 'valor' => $gestorUser->email_personal],
                ['etiqueta' => 'Estado', 'valor' => $gestorUser->estado?->value],
            ],
        ];
    }

    /**
     * La cuenta en Adagio, que es donde el email es la credencial.
     *
     * Cuando la persona todavía no tiene cuenta en Adagio se informa en vez de
     * dejar huecos: un bloque vacío se lee como un dato que falta, y aquí lo
     * que falta es que el alta en el proveedor de identidad no ha ocurrido.
     *
     * @return array{clave: string, titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function bloqueCuentaAdagio(GestorUser $gestorUser): array
    {
        $cuenta = $this->cuentaAdagio($gestorUser);

        if ($cuenta === null) {
            return [
                'clave' => 'cuenta_adagio',
                'titulo' => 'Cuenta en Adagio',
                'descripcion' => 'La cuenta del propietario en Adagio, que es el proveedor de identidad.',
                'campos' => [
                    ['etiqueta' => 'Cuenta', 'valor' => 'Sin cuenta en Adagio'],
                ],
            ];
        }

        $meta = is_array($cuenta->meta) ? $cuenta->meta : [];

        return [
            'clave' => 'cuenta_adagio',
            'titulo' => 'Cuenta en Adagio',
            'descripcion' => 'La cuenta del propietario en Adagio, que es el proveedor de identidad.',
            'campos' => [
                ['etiqueta' => 'Nombre en Adagio', 'valor' => $this->texto($meta['nome'] ?? null)],
                ['etiqueta' => 'Documento', 'valor' => $this->texto($meta['documento'] ?? null)],
                ['etiqueta' => 'E-mail', 'valor' => $cuenta->credencial_usuario ?: $this->texto($meta['email'] ?? null)],
                ['etiqueta' => 'ID externo', 'valor' => $cuenta->external_account_id ?: $this->texto($meta['id'] ?? null)],
                ['etiqueta' => 'Estado', 'valor' => $cuenta->estado?->value],
                ['etiqueta' => 'Tipo de contrato', 'valor' => $this->texto($meta['tipo_contrato'] ?? null)],
                ['etiqueta' => 'Contexto', 'valor' => $this->contextos($meta)],
            ],
        ];
    }

    /**
     * La cuenta en Adagio de esta persona, si la tiene.
     */
    private function cuentaAdagio(GestorUser $gestorUser): ?UserSubsystemAccount
    {
        return $gestorUser->subsystemAccounts->first(
            fn (UserSubsystemAccount $cuenta): bool => $cuenta->subsystem?->slug === 'adagio',
        );
    }

    /**
     * Los contextos del propietario, que son las unidades organizativas en las
     * que Adagio lo tiene dado de alta.
     *
     * @param  array<string, mixed>  $meta
     */
    private function contextos(array $meta): ?string
    {
        $contextos = $meta['contextos'] ?? null;

        if (! is_array($contextos)) {
            return null;
        }

        $nombres = collect($contextos)
            ->map(fn (mixed $contexto): ?string => is_array($contexto) ? ($contexto['contexto'] ?? null) : null)
            ->filter(fn (?string $nombre): bool => filled($nombre))
            ->unique()
            ->values();

        return $nombres->isEmpty() ? null : $nombres->implode(', ');
    }

    /**
     * Normaliza un valor de la respuesta de Adagio a texto.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? 'Sí' : 'No';
        }

        return (string) $valor;
    }
}
