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
     * Secciones del cartorial, en el orden en que se muestran.
     *
     * Cada sección es una fila de paneles: los paneles de una misma sección se
     * pintan uno al lado del otro, y los de secciones distintas se apilan. Es
     * lo que permite que la cuenta de Adagio y la de GLPI se lean juntas sin
     * que el servicio tenga que saber nada de la maquetación: si mañana hace
     * falta la cuenta de otro subsistema, es un panel más en la misma sección.
     *
     * @return list<array{clave: string, paneles: list<array{titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}>}>
     */
    public function bloques(GestorUser $gestorUser): array
    {
        return [
            [
                'clave' => 'identificacion',
                'paneles' => [
                    $this->panelIdentificacion($gestorUser),
                ],
            ],
            [
                'clave' => 'cuentas',
                'paneles' => [
                    $this->panelCuentaAdagio($gestorUser),
                    $this->panelCuentaGlpi($gestorUser),
                ],
            ],
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
     * @return array{titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function panelIdentificacion(GestorUser $gestorUser): array
    {
        return [
            'titulo' => 'Identificação',
            'descripcion' => 'Dados com os quais a pessoa está cadastrada no Gestor.',
            'campos' => [
                ['etiqueta' => 'Nome', 'valor' => $gestorUser->nombre_completo],
                ['etiqueta' => 'Usuário', 'valor' => $gestorUser->usuario],
                ['etiqueta' => 'Empresa', 'valor' => $gestorUser->empresa],
                ['etiqueta' => 'CPF', 'valor' => $gestorUser->cpf],
                ['etiqueta' => 'E-mail', 'valor' => $gestorUser->email_personal],
            ],
        ];
    }

    /**
     * La cuenta en Adagio, que es donde el email es la credencial.
     *
     * Cuando la persona todavía no tiene cuenta en Adagio se informa en vez de
     * dejar huecos: un panel vacío se lee como un dato que falta, y aquí lo
     * que falta es que el alta en el proveedor de identidad no ha ocurrido.
     *
     * @return array{titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function panelCuentaAdagio(GestorUser $gestorUser): array
    {
        $cuenta = $this->cuentaDe($gestorUser, 'adagio');

        if ($cuenta === null) {
            return [
                'titulo' => 'Conta em Adagio',
                'descripcion' => 'A conta do proprietário no Adagio, que é o provedor de identidade.',
                'campos' => [
                    ['etiqueta' => 'Conta', 'valor' => 'Sem conta em Adagio'],
                ],
            ];
        }

        return [
            'titulo' => 'Conta em Adagio',
            'descripcion' => 'A conta do proprietário no Adagio, que é o provedor de identidade.',
            'campos' => [
                ['etiqueta' => 'ID externo', 'valor' => $cuenta->external_account_id],
                ['etiqueta' => 'Status', 'valor' => $cuenta->estado?->value],
            ],
        ];
    }

    /**
     * La cuenta en GLPI, la mesa de ayuda.
     *
     * GLPI no guarda el email en la respuesta de la API: la credencial es el
     * login, y es lo que se muestra.
     *
     * @return array{titulo: string, descripcion: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function panelCuentaGlpi(GestorUser $gestorUser): array
    {
        $cuenta = $this->cuentaDe($gestorUser, 'glpi');

        if ($cuenta === null) {
            return [
                'titulo' => 'Conta em GLPI',
                'descripcion' => 'A conta do usuário no service desk (GLPI).',
                'campos' => [
                    ['etiqueta' => 'Conta', 'valor' => 'Sem conta em GLPI'],
                ],
            ];
        }

        return [
            'titulo' => 'Conta em GLPI',
            'descripcion' => 'A conta do usuário no service desk (GLPI).',
            'campos' => [
                ['etiqueta' => 'Login', 'valor' => $cuenta->credencial_usuario],
                ['etiqueta' => 'ID externo', 'valor' => $cuenta->external_account_id],
            ],
        ];
    }

    /**
     * La cuenta de esta persona en un subsistema, si la tiene.
     */
    private function cuentaDe(GestorUser $gestorUser, string $slug): ?UserSubsystemAccount
    {
        return $gestorUser->subsystemAccounts->first(
            fn (UserSubsystemAccount $cuenta): bool => $cuenta->subsystem?->slug === $slug,
        );
    }
}
