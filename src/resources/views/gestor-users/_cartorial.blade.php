{{--
    Cartorial del gestor.

    Los bloques los arma GestorUserCartorialService: esta plantilla solo los
    pinta. Añadir un bloque nuevo (un proceso, un documento) es añadir una
    entrada a la lista del servicio, no tocar aquí.

    No lleva título propio: el panel ya está etiquetado por su pestaña
    (aria-labelledby), y repetir el nombre "Cartorial" dentro del panel solo
    añadiría ruido.

    El x-data de la raíz es el que abre la ficha de un medio: envuelve a los
    bloques y al modal, para que el botón del listado pueda llamarlo.
--}}
<div id="cartorial"
    class="pt-6"
    x-data="{
                medio: null,
                cargando: false,
                error: null,
                async abrir(tipo, id) {
                    this.cargando = true;
                    this.error = null;

                    try {
                        const respuesta = await fetch(@js(route('gestor-users.medios.show', [$gestorUser, 'TIPO', 'ID']))
                            .replace('TIPO', encodeURIComponent(tipo))
                            .replace('ID', encodeURIComponent(id)), {
                            headers: { 'Accept': 'application/json' },
                        });

                        if (! respuesta.ok) {
                            throw new Error('HTTP ' + respuesta.status);
                        }

                        this.medio = await respuesta.json();
                    } catch (e) {
                        this.error = 'Não foi possível consultar o meio no GLPI.';
                    } finally {
                        this.cargando = false;
                    }
                },
            }"
    @keydown.escape.window="medio = null">

    <p class="mb-4 text-sm text-[#68756d]">
        Segunda constancia documental del gestor: quién es, con qué cuenta fue dada de alta
        y, a medida que se incorporen, los procesos y documentos asociados.
    </p>

    @if (empty($cartorial))
    <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">
        Todavía no hay nada registrado en el cartorial de este gestor.
    </div>
    @else
    <div class="space-y-6">
        @foreach ($cartorial as $seccion)
        {{-- Los paneles de una misma sección van uno al lado del otro: la
             identificación y la cuenta de Adagio se leen juntas. La grid es de
             12 columnas para que un panel pueda ocupar la fila entera, como
             el de GLPI, que lleva dentro el listado de medios. --}}
        <div class="grid items-start gap-6 lg:grid-cols-12">
            @foreach ($seccion['paneles'] as $panel)
            <div class="rounded-xl border border-[#d9e2dc] bg-white {{ ($panel['ancho'] ?? 'mitad') === 'completo' ? 'lg:col-span-12' : 'lg:col-span-6' }}">
                <div class="border-b border-[#d9e2dc] bg-[#f7faf8] px-4 py-3">
                    <h3 class="font-bold text-[#17211b]">{{ $panel['titulo'] }}</h3>
                    @if (filled($panel['descripcion']))
                    <p class="mt-0.5 text-xs text-[#68756d]">{{ $panel['descripcion'] }}</p>
                    @endif
                </div>

                <dl class="grid gap-px bg-[#e8eee9] sm:grid-cols-2">
                    @foreach ($panel['campos'] as $campo)
                    <div class="bg-white px-4 py-3">
                        <dt class="text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">{{ $campo['etiqueta'] }}</dt>
                        <dd class="mt-0.5 break-words text-[#17211b] {{ $campo['valor'] === null ? 'text-[#a3b0a8]' : '' }}">
                            {{ $campo['valor'] ?? 'No informado' }}
                        </dd>
                    </div>
                    @endforeach
                </dl>

                {{-- Listado dentro del panel: es el que le pertenece (los
                     medios son de la cuenta de GLPI). --}}
                @if (! empty($panel['tabla']))
                <div class="border-t border-[#d9e2dc]">
                    <div class="px-4 py-3">
                        <h4 class="text-sm font-bold text-[#17211b]">{{ $panel['tabla']['titulo'] }}</h4>
                        @if (filled($panel['tabla']['descripcion']))
                        <p class="mt-0.5 text-xs text-[#68756d]">{{ $panel['tabla']['descripcion'] }}</p>
                        @endif
                    </div>

                    @if (! empty($panel['tabla']['filas']))
                    @include('gestor-users._cartorial-tabla', [
                        'columnas' => $panel['tabla']['columnas'],
                        'filas' => $panel['tabla']['filas'],
                    ])
                    @else
                    <p class="px-4 pb-4 text-sm text-[#68756d]">
                        Nenhum meio atribuído a este usuário no GLPI.
                    </p>
                    @endif
                </div>
                @endif
            </div>
            @endforeach
        </div>
        @endforeach
    </div>
    @endif

    {{--
        Documentos del cartorial.

        Va después de los bloques porque es el listado que los complementa: los
        bloques dicen quién es la persona y con qué cuenta, y los documentos
        son lo que se le ha exigido y en qué situación está.
    --}}
    @if (! empty($cartorialDocumentos['filas']))
    <div class="mt-6 overflow-hidden rounded-xl border border-[#d9e2dc] bg-white">
        <div class="border-b border-[#d9e2dc] bg-[#f7faf8] px-4 py-3">
            <h3 class="font-bold text-[#17211b]">Documentos</h3>
            <p class="mt-0.5 text-xs text-[#68756d]">
                Documentos exigidos ao gestor e em que situação se encontra cada um.
            </p>
        </div>

        @include('gestor-users._cartorial-tabla', [
            'columnas' => $cartorialDocumentos['columnas'],
            'filas' => $cartorialDocumentos['filas'],
        ])
    </div>
    @endif

    {{--
        Ficha de un medio.

        Se abre al pulsar el nombre de un medio del listado: los datos del
        equipo no vienen en la página (son muchos y la ficha se abre en cada
        consulta), así que se piden a GLPI bajo demanda y se muestran aquí.
    --}}
    <div x-show="medio !== null || cargando"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="medio = null"></div>

        {{-- Contenido --}}
        <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Meio no inventário do GLPI</h2>
                <button type="button" @click="medio = null" class="text-[#68756d] hover:text-[#17211b]" aria-label="Fechar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                {{-- El estado de la consulta (cargando, error, datos) vive en
                     Alpine: lo que llega del servidor es el detalle ya
                     consultado. --}}
                <p x-show="cargando" x-cloak class="text-sm text-[#68756d]">A consultar o GLPI…</p>

                <p x-show="error !== null" x-cloak class="text-sm text-red-700" x-text="error"></p>

                <dl x-show="! cargando && error === null && medio !== null" x-cloak class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                    <template x-for="[rotulo, valor] in Object.entries(medio?.datos ?? {})" :key="rotulo">
                        <div>
                            <dt class="text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]" x-text="rotulo"></dt>
                            <dd class="mt-0.5 break-words text-[#17211b]" x-text="valor"></dd>
                        </div>
                    </template>
                </dl>
            </div>

            <div x-show="medio?.url_inventario" x-cloak class="flex justify-end border-t border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <a :href="medio?.url_inventario"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-lg border border-[#d9e2dc] bg-white px-4 py-2 text-sm font-bold text-[#17211b] transition hover:bg-[#f7faf8] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                    Abrir no inventário do GLPI
                </a>
            </div>
        </div>
    </div>
</div>
