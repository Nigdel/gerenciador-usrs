<?php

namespace App\Services;

use App\Models\GestorUser;
use App\Models\Termo;
use App\Models\UserSubsystemAccount;
use App\Services\Subsystems\GlpiMediosService;
use App\Services\Termos\TermoService;
use Illuminate\Support\Collection;

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
 *
 * Los medios son la excepción: salen de GLPI, que es donde están, y el
 * servicio de medios se encarga de la llamada y de su caché.
 */
class GestorUserCartorialService
{
    public function __construct(
        private readonly GlpiMediosService $mediosGlpi,
        private readonly TermoService $termos,
    ) {}

    /**
     * Secciones del cartorial, en el orden en que se muestran.
     *
     * Cada sección es una fila de paneles: los paneles de una misma sección se
     * pintan uno al lado del otro, y los de secciones distintas se apilan. Un
     * panel puede ocupar la fila entera (`ancho` => 'completo'), que es lo que
     * hace el de GLPI: lleva dentro el listado de medios y con la mitad del
     * ancho no cabía.
     *
     * Un panel puede llevar además un listado (`tabla`): es lo que hacen los
     * medios, que cuelgan de la cuenta de GLPI y por eso van dentro de su
     * panel en vez de en una sección aparte.
     *
     * @return list<array{clave: string, paneles: list<array{titulo: string, descripcion: string, ancho: string, campos: list<array{etiqueta: string, valor: ?string}>, tabla?: array{titulo: string, descripcion: string, columnas: list<string>, filas: list<array<string, mixed>>}>}>}>
     */
    public function bloques(GestorUser $gestorUser): array
    {
        return [
            [
                // Identificación y Adagio en la misma fila: la cuenta del
                // proveedor de identidad se lee junto a los datos de la persona.
                'clave' => 'identificacion',
                'paneles' => [
                    $this->panelIdentificacion($gestorUser),
                    $this->panelCuentaAdagio($gestorUser),
                ],
            ],
            [
                // GLPI ocupa la fila entera: su panel lleva el listado de medios.
                'clave' => 'glpi',
                'paneles' => [
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
     * Las columnas del listado de medios.
     *
     * Las define el servicio de medios, que es quien construye las filas: si
     * cada uno por su cuenta, un cambio de nombre en uno rompe el listado sin
     * que se note (la celda sale vacía, no un error).
     *
     * @return list<string>
     */
    public function columnasMedios(): array
    {
        // "Termo" es una columna más del listado porque el documento cuelga
        // del medio: así se ve de un vistazo qué equipos están cubiertos y
        // cuáles todavía no.
        return [...$this->mediosGlpi->columnas(), 'Termo'];
    }

    /**
     * Los medios atribuidos a una cuenta de GLPI, con el último termo de cada
     * uno.
     *
     * El estado se toma del historial completo en una sola consulta: la ficha
     * puede tener pocos medios y no hace falta preguntar por cada fila.
     *
     * @return list<array<string, mixed>>
     */
    public function mediosDe(GestorUser $gestorUser, UserSubsystemAccount $cuenta): array
    {
        $ultimos = $this->termos->ultimosPorMedio($gestorUser);

        return array_map(function (array $fila) use ($ultimos, $gestorUser): array {
            $medio = $fila['medio'] ?? null;
            $termo = is_array($medio)
                ? ($ultimos[$medio['tipo'].':'.$medio['id']] ?? null)
                : null;

            $fila['Termo'] = $termo?->estado->etiqueta() ?? 'Sem termo';
            $fila['termo'] = $termo instanceof Termo
                ? [
                    'id' => (string) $termo->id,
                    'estado' => $termo->estado->value,
                    'etiqueta' => $termo->estado->etiqueta(),
                    'vigente' => $termo->esVigente(),
                    'pdf' => route('gestor-users.termos.pdf', [$gestorUser, $termo]),
                ]
                : null;

            return $fila;
        }, $this->mediosGlpi->medios($cuenta));
    }

    /**
     * Todos los termos del gestor, del más reciente al más antiguo.
     *
     * @return Collection<int, Termo>
     */
    public function termosDe(GestorUser $gestorUser): Collection
    {
        return $this->termos->historial($gestorUser);
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
     * @return array{titulo: string, descripcion: string, ancho: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function panelIdentificacion(GestorUser $gestorUser): array
    {
        return [
            'titulo' => 'Identificação',
            'descripcion' => 'Dados com os quais a pessoa está cadastrada no Gestor.',
            'ancho' => 'mitad',
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
     * @return array{titulo: string, descripcion: string, ancho: string, campos: list<array{etiqueta: string, valor: ?string}>}
     */
    private function panelCuentaAdagio(GestorUser $gestorUser): array
    {
        $cuenta = $this->cuentaDe($gestorUser, 'adagio');

        if ($cuenta === null) {
            return [
                'titulo' => 'Conta em Adagio',
                'descripcion' => 'A conta do proprietário no Adagio, que é o provedor de identidade.',
                'ancho' => 'mitad',
                'campos' => [
                    ['etiqueta' => 'Conta', 'valor' => 'Sem conta em Adagio'],
                ],
            ];
        }

        return [
            'titulo' => 'Conta em Adagio',
            'descripcion' => 'A conta do proprietário no Adagio, que é o provedor de identidade.',
            'ancho' => 'mitad',
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
     * Los medios cuelgan de este panel y no de una sección aparte: en GLPI son
     * los "itens" vinculados al usuario, así que se leen junto a la cuenta que
     * los declara, no al final del cartorial.
     *
     * @return array{titulo: string, descripcion: string, ancho: string, campos: list<array{etiqueta: string, valor: ?string}>, tabla?: array{titulo: string, descripcion: string, columnas: list<string>, filas: list<array<string, mixed>>}>}
     */
    private function panelCuentaGlpi(GestorUser $gestorUser): array
    {
        $cuenta = $this->cuentaDe($gestorUser, 'glpi');

        if ($cuenta === null) {
            return [
                'titulo' => 'Conta em GLPI',
                'descripcion' => 'A conta do usuário no service desk (GLPI).',
                'ancho' => 'completo',
                'campos' => [
                    ['etiqueta' => 'Conta', 'valor' => 'Sem conta em GLPI'],
                ],
            ];
        }

        return [
            'titulo' => 'Conta em GLPI',
            'descripcion' => 'A conta do usuário no service desk (GLPI) e os meios atribuídos a ela.',
            'ancho' => 'completo',
            'campos' => [
                ['etiqueta' => 'Login', 'valor' => $cuenta->credencial_usuario],
                ['etiqueta' => 'ID externo', 'valor' => $cuenta->external_account_id],
            ],
            'tabla' => [
                'titulo' => 'Meios',
                'descripcion' => 'Equipamentos e dispositivos atribuídos a este usuário no GLPI.',
                'columnas' => $this->columnasMedios(),
                'filas' => $this->mediosDe($gestorUser, $cuenta),
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
