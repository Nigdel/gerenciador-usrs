<?php

namespace App\Services\Subsystems;

use App\Models\Subsystem;
use App\Models\UserSubsystemAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Lee los medios (equipos y dispositivos) atribuidos a un usuario en GLPI.
 *
 * En GLPI los medios son los "itens" de tipo Computer, Peripheral, Phone,
 * Monitor o Printer, y el vínculo con la persona son las columnas `users_id`
 * (usuarios asignados) y `users_id_tech` (usuarios técnicos) de cada tipo de
 * equipo. La API no expone un endpoint único para "los equipos de este
 * usuario", así que el camino es el que usa el propio GLPI en su pestaña
 * "Ítens utilizados": buscar por criterio de usuario y leer cada equipo.
 *
 * Dos detalles de la API que condicionan cómo se busca, y que se comprobaron
 * contra la instancia real:
 *
 *  - El criterio de búsqueda de un dropdown (el usuario) sí acepta el id del
 *    usuario con `equals`, que es lo preciso: con `contains` el mismo
 *    criterio trae homónimos ("ana" trae "alana.barbosa") y obliga a filtrar
 *    el resultado a posteriori.
 *  - El número de la opción de búsqueda de cada columna es distinto en cada
 *    tipo de equipo, así que se lee de `listSearchOptions` en vez de fijarlo.
 *    Y hace falta pedirlo con `raw`, porque sin ese parámetro la API no
 *    devuelve el `linkfield`, que es por donde se reconoce cada columna.
 *
 * Extiende GlpiService para reutilizar su sesión (initSession + Session-Token
 * cacheado): es la misma autenticación que la de escritura, y el token que
 * GLPI ya tiene cacheado.
 */
class GlpiMediosService extends GlpiService
{
    /**
     * Tipos de equipo que se consultan si el subsistema no dice lo contrario.
     *
     * @var list<string>
     */
    private const TIPOS_PADRAO = ['Computer', 'Peripheral', 'Phone', 'Monitor', 'Printer'];

    /**
     * Etiquetas en portugués de cada tipo de equipo.
     *
     * @var array<string, string>
     */
    private const TIPOS = [
        'Computer' => 'Computador',
        'Peripheral' => 'Periférico',
        'Phone' => 'Celular',
        'Monitor' => 'Monitor',
        'Printer' => 'Impressora',
        'NetworkEquipment' => 'Equipamento de rede',
    ];

    /**
     * Los medios atribuidos a la persona, listos para pintarse.
     *
     * @return list<array<string, ?string>>
     */
    public function medios(UserSubsystemAccount $cuenta): array
    {
        $subsistema = $cuenta->subsystem;
        $usuario = trim((string) $cuenta->external_account_id);

        if ($subsistema === null || $usuario === '') {
            return [];
        }

        // La atribución de equipos cambia poco y la ficha se abre en cada
        // consulta: sin caché, cada visita pagaría varias llamadas a GLPI.
        $cacheKey = "glpi:medios:{$subsistema->id}:{$usuario}";

        return Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn (): array => $this->consultarMedios($subsistema, $usuario),
        );
    }

    /**
     * Las columnas del listado de medios.
     *
     * @return list<string>
     */
    public function columnas(): array
    {
        return [
            'Tipo',
            'Descrição',
            'Nº de Série',
            'Status',
            'Localização',
            'Atribuído em',
        ];
    }

    /**
     * @return list<array<string, ?string>>
     */
    private function consultarMedios(Subsystem $subsistema, string $usuario): array
    {
        $filas = [];

        foreach ($this->tipos($subsistema) as $tipo) {
            foreach ($this->itemsDelUsuario($subsistema, $tipo, $usuario) as $item) {
                $filas[] = $this->fila($tipo, $item, $subsistema);
            }
        }

        return $filas;
    }

    /**
     * El detalle de un medio, tal como está en el inventario de GLPI.
     *
     * @return array{datos: array<string, ?string>, url_inventario: ?string}|null
     */
    public function medio(Subsystem $subsistema, string $tipo, string $id): ?array
    {
        if (! $this->esTipoValido($subsistema, $tipo) || ! ctype_digit($id)) {
            return null;
        }

        $item = $this->item($subsistema, $tipo, $id);

        if ($item === null) {
            return null;
        }

        return [
            'datos' => $this->datos($tipo, $item),
            'url_inventario' => $this->urlInventario($subsistema, $tipo, $id),
        ];
    }

    /**
     * Los campos del medio, con su rótulo y sin los que vienen vacíos.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, ?string>
     */
    private function datos(string $tipo, array $item): array
    {
        $campos = [
            'id' => 'ID',
            'name' => 'Nome',
            'serial' => 'Número de série',
            'otherserial' => 'Número de inventário',
            'states_id' => 'Status',
            'locations_id' => 'Localização',
            'entities_id' => 'Entidade',
            'brand' => 'Marca',
            'users_id' => 'Usuário',
            'users_id_tech' => 'Técnico responsável',
            'contact' => 'Contacto',
            'contact_num' => 'Telefone',
            'comment' => 'Comentários',
            'date_creation' => 'Cadastro',
            'date_mod' => 'Última alteração',
            'uuid' => 'UUID',
        ];

        $datos = [];

        foreach ($campos as $campo => $rotulo) {
            $valor = $this->desplegable($item[$campo] ?? null);

            if ($valor !== null) {
                $datos[$rotulo] = $valor;
            }
        }

        // El tipo, el modelo y el fabricante tienen un nombre distinto en cada
        // tipo de equipo (computertypes_id, peripheraltypes_id, phonetypes_id…),
        // así que se localizan por el sufijo en vez de por nombre exacto.
        foreach (['types_id' => 'Tipo', 'models_id' => 'Modelo', 'manufacturers_id' => 'Fabricante'] as $sufijo => $rotulo) {
            $valor = $this->desplegable($this->campoPorSufijo($item, $sufijo));

            if ($valor !== null) {
                $datos[$rotulo] = $valor;
            }
        }

        return $datos;
    }

    /**
     * El primer campo de la respuesta que acaba en el sufijo dado.
     *
     * @param  array<string, mixed>  $item
     */
    private function campoPorSufijo(array $item, string $sufijo): mixed
    {
        foreach ($item as $campo => $valor) {
            if (str_ends_with((string) $campo, $sufijo)) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * La página del medio en el inventario de GLPI.
     *
     * La URL web no es la de la API: apirest.php vive en la raíz del GLPI, y
     * la ficha de un equipo está en front/{tipo}.php?id={id}.
     */
    public function urlInventario(Subsystem $subsistema, string $tipo, string $id): ?string
    {
        $config = $subsistema->api_config ?? [];

        $base = trim((string) ($config['url'] ?? ''));

        if ($base === '') {
            $base = (string) $subsistema->api_url;
        }

        // Si lo configurado es la ruta de la API, la raíz web es lo que queda
        // antes de apirest.php.
        $raiz = preg_replace('#/apirest\.php.*$#', '', $base) ?: $base;

        if ($raiz === '') {
            return null;
        }

        return rtrim($raiz, '/').'/front/'.strtolower($tipo).'.php?id='.urlencode($id);
    }

    /**
     * Si el tipo de equipo es de los que se consultan.
     */
    private function esTipoValido(Subsystem $subsistema, string $tipo): bool
    {
        return in_array($tipo, $this->tipos($subsistema), true);
    }

    /**
     * Los equipos de un tipo que están atribuidos al usuario.
     *
     * @return list<array<string, mixed>>
     */
    private function itemsDelUsuario(Subsystem $subsistema, string $tipo, string $usuario): array
    {
        $opciones = $this->searchOptions($subsistema, $tipo);

        if ($opciones === []) {
            return [];
        }

        $criterios = [];
        $enunciados = array_values(array_filter([
            $opciones['users_id'] ?? null,
            $opciones['users_id_tech'] ?? null,
        ]));

        foreach ($enunciados as $indice => $opcion) {
            if ($indice > 0) {
                $criterios["criteria[{$indice}][link]"] = 'OR';
            }

            $criterios["criteria[{$indice}][field]"] = $opcion;
            $criterios["criteria[{$indice}][searchtype]"] = 'equals';
            $criterios["criteria[{$indice}][value]"] = $usuario;
        }

        if ($criterios === []) {
            return [];
        }

        // Se piden explícitamente las columnas que hacen falta: sin esto GLPI
        // devuelve las que el usuario tiene en sus preferencias de pantalla.
        $columnas = array_values(array_filter([
            $opciones['id'] ?? null,
            $opciones['name'] ?? null,
        ]));

        foreach ($columnas as $indice => $opcion) {
            $criterios["forcedisplay[{$indice}]"] = $opcion;
        }

        $criterios['range'] = '0-99';

        try {
            $response = $this->http($subsistema)->get('/search/'.$tipo, $criterios);
        } catch (Throwable $exception) {
            $this->log('No se pudieron buscar los medios en GLPI', [
                'tipo' => $tipo,
                'usuario' => $usuario,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }

        if ($response->failed()) {
            $this->log('GLPI rechazó la búsqueda de medios', [
                'tipo' => $tipo,
                'usuario' => $usuario,
                'http_status' => $response->status(),
            ]);

            return [];
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            return [];
        }

        $columnaId = (string) ($opciones['id'] ?? '');
        $items = [];

        foreach ($data as $fila) {
            if (! is_array($fila)) {
                continue;
            }

            $id = $fila[$columnaId] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $item = $this->item($subsistema, $tipo, (string) $id);

            if ($item !== null) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * El detalle de un equipo, con los desplegables resueltos a nombre.
     *
     * @return array<string, mixed>|null
     */
    private function item(Subsystem $subsistema, string $tipo, string $id): ?array
    {
        try {
            $response = $this->http($subsistema)->get('/'.$tipo.'/'.$id, [
                'expand_dropdowns' => 'true',
            ]);
        } catch (Throwable $exception) {
            $this->log('No se pudo leer el detalle de un medio en GLPI', [
                'tipo' => $tipo,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($response->failed()) {
            $this->log('GLPI rechazó la lectura de un medio', [
                'tipo' => $tipo,
                'id' => $id,
                'http_status' => $response->status(),
            ]);

            return null;
        }

        $item = $response->json();

        return is_array($item) ? $item : null;
    }

    /**
     * Una fila del listado de medios, con los rótulos del cartorial.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, ?string>
     */
    private function fila(string $tipo, array $item, Subsystem $subsistema): array
    {
        $id = (string) ($item['id'] ?? '');

        return [
            'Tipo' => self::TIPOS[$tipo] ?? $tipo,
            'Descrição' => $this->texto($item['name'] ?? null),
            'Nº de Série' => $this->texto($item['serial'] ?? null) ?? $this->texto($item['otherserial'] ?? null),
            'Status' => $this->desplegable($item['states_id'] ?? null),
            'Localização' => $this->desplegable($item['locations_id'] ?? null),
            'Atribuído em' => $this->fecha($item['date_creation'] ?? null),
            // La fila lleva la referencia al medio: es lo que permite que su
            // nombre sea un enlace y que al pulsarlo se abra su ficha con los
            // datos que GLPI tiene de ese equipo.
            'enlace' => $this->urlInventario($subsistema, $tipo, $id),
            'medio' => [
                'tipo' => $tipo,
                'id' => $id,
            ],
        ];
    }

    /**
     * Un valor que viene de un desplegable de GLPI.
     *
     * Con `expand_dropdowns` GLPI devuelve el nombre del estado o de la
     * ubicación, pero 0 cuando el equipo no tiene ninguno asignado: es un id
     * sin desplegable, no un estado que se llame "0".
     */
    private function desplegable(mixed $valor): ?string
    {
        if ($valor === 0 || $valor === '0') {
            return null;
        }

        return $this->texto($valor);
    }

    /**
     * Las opciones de búsqueda de un tipo de equipo, con la opción que
     * corresponde a cada columna que nos interesa.
     *
     * GLPI numera las opciones de búsqueda de cada tipo de equipo de forma
     * distinta, así que el número se busca por el campo al que apuntan en vez
     * de estar escrito a mano: un número cambiado en una actualización de GLPI
     * traería los equipos de otra columna, o directamente ninguno.
     *
     * @return array<string, int>
     */
    private function searchOptions(Subsystem $subsistema, string $tipo): array
    {
        $cacheKey = "glpi:searchoptions:{$subsistema->id}:{$tipo}";

        return Cache::remember($cacheKey, now()->addHours(6), function () use ($subsistema, $tipo): array {
            try {
                // Sin `raw` la API no devuelve el `linkfield`, que es
                // justamente por donde se reconoce cada columna.
                $response = $this->http($subsistema)->get('/listSearchOptions/'.$tipo, ['raw' => 'true']);
            } catch (Throwable $exception) {
                $this->log('No se pudieron leer las opciones de búsqueda de GLPI', [
                    'tipo' => $tipo,
                    'error' => $exception->getMessage(),
                ]);

                return [];
            }

            if ($response->failed()) {
                return [];
            }

            $opciones = $response->json();

            return is_array($opciones) ? $this->indexarOpciones($opciones, $tipo) : [];
        });
    }

    /**
     * Traduce el listado de opciones de GLPI al mapa de columnas que se usan.
     *
     * Cada opción trae la columna que se muestra ('field') y la columna por la
     * que se enlaza con el equipo ('linkfield'). Las columnas del propio tipo
     * de equipo se distinguen porque su `linkfield` no lleva sufijo `_id`: el
     * id y el nombre son los únicos que apuntan a sí mismos.
     *
     * @param  array<string, mixed>  $opciones
     * @return array<string, int>
     */
    private function indexarOpciones(array $opciones, string $tipo): array
    {
        $mapa = [];

        foreach ($opciones as $id => $opcion) {
            if (! is_array($opcion)) {
                continue;
            }

            $id = (int) $id;
            $campo = $opcion['linkfield'] ?? null;
            $propio = ($opcion['field'] ?? null) === $campo;

            // El id y el nombre del propio equipo: son las únicas columnas que
            // se enlazan consigo mismas.
            if ($propio && $campo === 'id') {
                $mapa['id'] = $id;
            }

            if ($propio && $campo === 'name') {
                $mapa['name'] = $id;
            }

            // El usuario tiene varias opciones que apuntan a `users_id`: la
            // del propio equipo, la del autor de una nota y la de un
            // appliance. Solo la primera enlaza con la tabla del equipo, y es
            // la que no arrastra joinparams.
            if (($campo ?? null) === 'users_id'
                && ($opcion['table'] ?? null) === 'glpi_users'
                && empty($opcion['joinparams'])) {
                $mapa['users_id'] = $id;
            }

            if (($campo ?? null) === 'users_id_tech' && ($opcion['table'] ?? null) === 'glpi_users') {
                $mapa['users_id_tech'] = $id;
            }
        }

        return $mapa;
    }

    /**
     * Los tipos de equipo a consultar, los que diga el subsistema o los
     * habituales.
     *
     * @return list<string>
     */
    private function tipos(Subsystem $subsistema): array
    {
        $configurados = $subsistema->api_config['itemtypes'] ?? null;

        if (is_array($configurados) && $configurados !== []) {
            return array_values(array_filter($configurados, 'is_string'));
        }

        return self::TIPOS_PADRAO;
    }

    /**
     * Normaliza un valor de la respuesta de GLPI a texto.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if (is_bool($valor)) {
            return $valor ? 'Sim' : 'Não';
        }

        if (is_array($valor)) {
            return null;
        }

        return (string) $valor;
    }

    /**
     * Formatea la fecha de GLPI en el formato que usa el resto de la web.
     */
    private function fecha(mixed $valor): ?string
    {
        $texto = $this->texto($valor);

        if ($texto === null) {
            return null;
        }

        try {
            return Carbon::parse($texto)->format('d/m/Y');
        } catch (Throwable) {
            return $texto;
        }
    }
}
