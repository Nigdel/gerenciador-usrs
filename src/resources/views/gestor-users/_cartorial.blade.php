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
                termoNuevo: {
                    abierto: false,
                    cargando: false,
                    error: null,
                    item_type: null,
                    item_id: null,
                    datos: null,
                    form: {
                        tipo: 'entrega',
                        cnpj: '',
                        procesador: '',
                        color: '',
                        ram: '',
                        almacenamiento: '',
                        mac: '',
                        estado_conservacion: '',
                        observaciones: '',
                    },
                },
                firmado: { abierto: false, termo: null, url: null },
                anulacion: { abierto: false, termo: null, url: null, motivo: '' },
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
                async abrirTermo(tipo, id) {
                    this.termoNuevo = {
                        ...this.termoNuevo,
                        abierto: true,
                        cargando: true,
                        error: null,
                        datos: null,
                        item_type: tipo,
                        item_id: String(id),
                        form: {
                            tipo: 'entrega',
                            cnpj: '',
                            procesador: '',
                            color: '',
                            ram: '',
                            almacenamiento: '',
                            mac: '',
                            estado_conservacion: '',
                            observaciones: '',
                        },
                    };

                    try {
                        const respuesta = await fetch(@js(route('gestor-users.termos.formulario', [$gestorUser, 'TIPO', 'ID']))
                            .replace('TIPO', encodeURIComponent(tipo))
                            .replace('ID', encodeURIComponent(id)), {
                            headers: { 'Accept': 'application/json' },
                        });

                        const datos = await respuesta.json();

                        if (! respuesta.ok) {
                            throw new Error(datos.message || 'HTTP ' + respuesta.status);
                        }

                        this.termoNuevo.datos = datos;
                        this.termoNuevo.form = {
                            tipo: 'entrega',
                            cnpj: datos.empresa?.cnpj ?? '',
                            procesador: datos.dispositivo?.procesador ?? '',
                            color: '',
                            ram: datos.dispositivo?.ram ?? '',
                            almacenamiento: datos.dispositivo?.almacenamiento ?? '',
                            mac: datos.dispositivo?.mac ?? '',
                            estado_conservacion: datos.dispositivo?.estado ?? '',
                            observaciones: '',
                        };
                    } catch (e) {
                        this.termoNuevo.error = e.message || 'Não foi possível consultar o meio no GLPI.';
                    } finally {
                        this.termoNuevo.cargando = false;
                    }
                },
                fecharTermo() {
                    this.termoNuevo.abierto = false;
                },
                abrirFirmado(id, url) {
                    this.firmado = { abierto: true, termo: id, url: url };
                },
                abrirAnulacion(id, url) {
                    this.anulacion = { abierto: true, termo: id, url: url, motivo: '' };
                },
            }"
    @keydown.escape.window="medio = null; termoNuevo.abierto = false; firmado.abierto = false; anulacion.abierto = false">

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
                        'gestorUser' => $gestorUser,
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
        Termos de responsabilidade.

        Los termos son la constancia documental de la entrega de cada medio.
        Van después de los bloques porque su origen es el equipo de GLPI, y
        antes de los documentos generales porque son el documento que este
        cartorial produce, no uno que se reciba de fuera.
    --}}
    <div class="mt-6 overflow-hidden rounded-xl border border-[#d9e2dc] bg-white">
        <div class="border-b border-[#d9e2dc] bg-[#f7faf8] px-4 py-3">
            <h3 class="font-bold text-[#17211b]">Termos de responsabilidade</h3>
            <p class="mt-0.5 text-xs text-[#68756d]">
                Termos emitidos para os equipamentos deste gestor e em que etapa do ciclo de vida estão.
            </p>
        </div>

        @if (($cartorialTermos ?? collect())->isEmpty())
        <p class="px-4 py-4 text-sm text-[#68756d]">
            Nenhum termo emitido. Gere um termo a partir da coluna «Termo» do listado de meios.
        </p>
        @else
        @include('gestor-users._cartorial-termos', [
            'termos' => $cartorialTermos,
            'gestorUser' => $gestorUser,
        ])
        @endif
    </div>

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
            'gestorUser' => $gestorUser,
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

    @can('generarTermo', $gestorUser)
    {{--
        Emisión del termo.

        Los datos del usuario y del equipo llegan de GLPI al abrir el modal y
        se muestran como lectura: lo único editable son los ajustes que el
        inventario no siempre tiene (CNPJ, color, observaciones) y los
        componentes que GLPI puede no haber registrado.
    --}}
    <div x-show="termoNuevo.abierto"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4 sm:p-6">
        <div class="fixed inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="fecharTermo()"></div>

        <div class="relative my-8 w-full max-w-3xl overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Gerar termo de responsabilidade</h2>
                <button type="button" @click="fecharTermo()" class="text-[#68756d] hover:text-[#17211b]" aria-label="Fechar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <div class="p-6">
                <p x-show="termoNuevo.cargando" x-cloak class="text-sm text-[#68756d]">A consultar o equipamento no GLPI…</p>
                <p x-show="termoNuevo.error !== null" x-cloak class="text-sm font-bold text-red-700" x-text="termoNuevo.error"></p>

                <form x-show="! termoNuevo.cargando && termoNuevo.error === null && termoNuevo.datos !== null"
                    x-cloak
                    action="{{ route('gestor-users.termos.store', $gestorUser) }}"
                    method="POST">
                    @csrf
                    <input type="hidden" name="item_type" :value="termoNuevo.item_type">
                    <input type="hidden" name="item_id" :value="termoNuevo.item_id">

                    <div class="mb-5 grid gap-3 rounded-lg border border-[#e8eee9] bg-[#f7faf8] p-4 text-sm sm:grid-cols-2">
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Colaborador</span>
                            <span class="text-[#17211b]" x-text="termoNuevo.datos?.usuario?.nombre ?? '—'"></span>
                            <span class="block font-mono text-xs text-[#68756d]" x-text="'CPF: ' + (termoNuevo.datos?.usuario?.cpf ?? '—')"></span>
                        </div>
                        <div>
                            <span class="block text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Equipamento</span>
                            <span class="text-[#17211b]" x-text="(termoNuevo.datos?.dispositivo?.tipo ?? '') + ' · ' + (termoNuevo.datos?.dispositivo?.nombre ?? '—')"></span>
                            <span class="block font-mono text-xs text-[#68756d]" x-text="'Série: ' + (termoNuevo.datos?.dispositivo?.serial ?? '—')"></span>
                        </div>
                        <div class="sm:col-span-2">
                            <span class="block text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Empresa</span>
                            <span class="text-[#17211b]" x-text="termoNuevo.datos?.empresa?.nombre ?? '—'"></span>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_tipo">Tipo de termo*</label>
                            <select id="termo_tipo" name="tipo" x-model="termoNuevo.form.tipo" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                                <template x-for="tipo in termoNuevo.datos?.tipos ?? []" :key="tipo.valor">
                                    <option :value="tipo.valor" x-text="tipo.etiqueta"></option>
                                </template>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_cnpj">CNPJ da empresa</label>
                            <input id="termo_cnpj" name="cnpj" type="text" maxlength="20" x-model="termoNuevo.form.cnpj" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_procesador">Processador</label>
                            <input id="termo_procesador" name="procesador" type="text" maxlength="150" x-model="termoNuevo.form.procesador" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_color">Cor</label>
                            <input id="termo_color" name="color" type="text" maxlength="50" x-model="termoNuevo.form.color" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_ram">Memória</label>
                            <input id="termo_ram" name="ram" type="text" maxlength="50" x-model="termoNuevo.form.ram" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_almacenamiento">Armazenamento</label>
                            <input id="termo_almacenamiento" name="almacenamiento" type="text" maxlength="50" x-model="termoNuevo.form.almacenamiento" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_mac">MAC / IMEI</label>
                            <input id="termo_mac" name="mac" type="text" maxlength="100" x-model="termoNuevo.form.mac" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_estado">Estado de conservação</label>
                            <input id="termo_estado" name="estado_conservacion" type="text" maxlength="100" x-model="termoNuevo.form.estado_conservacion" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                        </div>

                        <div class="sm:col-span-2">
                            <label class="mb-1 block text-sm font-bold text-[#17211b]" for="termo_observaciones">Observações</label>
                            <textarea id="termo_observaciones" name="observaciones" rows="3" maxlength="2000" x-model="termoNuevo.form.observaciones" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20"></textarea>
                        </div>
                    </div>

                    @error('item_id')<small class="mt-2 block text-xs font-bold text-red-700">{{ $message }}</small>@enderror

                    <div class="mt-6 flex justify-end gap-3">
                        <button type="button" @click="fecharTermo()" class="inline-flex items-center justify-center rounded-lg border border-[#d9e2dc] bg-white px-4 py-2 text-sm font-bold text-[#17211b] hover:bg-[#f7faf8]">Cancelar</button>
                        <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800" :disabled="termoNuevo.datos === null">Gerar termo</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endcan

    @can('actualizarTermo', $gestorUser)
    {{-- Subida del PDF firmado --}}
    <div x-show="firmado.abierto"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
        <div class="fixed inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="firmado.abierto = false"></div>

        <div class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Anexar termo firmado</h2>
                <button type="button" @click="firmado.abierto = false" class="text-[#68756d] hover:text-[#17211b]" aria-label="Fechar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form :action="firmado.url" method="POST" enctype="multipart/form-data" class="p-6">
                @csrf
                <p class="mb-4 text-sm text-[#68756d]">O PDF assinado substitui o estado do termo por «Firmado». Guarde aqui o documento devolvido pelo colaborador.</p>
                <input type="file" name="pdf_firmado" accept="application/pdf,.pdf" required class="block w-full text-sm text-[#17211b] file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-700 file:px-3 file:py-2 file:text-sm file:font-bold file:text-white">
                @error('pdf_firmado')<small class="mt-2 block text-xs font-bold text-red-700">{{ $message }}</small>@enderror
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="firmado.abierto = false" class="inline-flex items-center justify-center rounded-lg border border-[#d9e2dc] bg-white px-4 py-2 text-sm font-bold text-[#17211b] hover:bg-[#f7faf8]">Cancelar</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">Guardar PDF</button>
                </div>
            </form>
        </div>
    </div>
    @endcan

    @can('anularTermo', $gestorUser)
    {{-- Anulación del termo --}}
    <div x-show="anulacion.abierto"
        x-cloak
        x-transition
        class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
        <div class="fixed inset-0 bg-[#17211b]/60 backdrop-blur-sm" @click="anulacion.abierto = false"></div>

        <div class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-[#d9e2dc] bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-[#d9e2dc] bg-[#f7faf8] px-6 py-4">
                <h2 class="text-xl font-bold text-[#17211b]">Anular termo</h2>
                <button type="button" @click="anulacion.abierto = false" class="text-[#68756d] hover:text-[#17211b]" aria-label="Fechar">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>

            <form :action="anulacion.url" method="POST" class="p-6">
                @csrf
                <p class="mb-4 text-sm text-[#68756d]">O termo anulado deixa de ser vigente, mas não se apaga: o histórico e os PDF continuam disponíveis. Depois de anulá-lo pode emitir outro para o mesmo equipamento.</p>
                <label class="mb-1 block text-sm font-bold text-[#17211b]" for="motivo_anulacion">Motivo*</label>
                <input id="motivo_anulacion" name="motivo_anulacion" type="text" minlength="5" maxlength="500" required x-model="anulacion.motivo" class="w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20">
                @error('motivo_anulacion')<small class="mt-2 block text-xs font-bold text-red-700">{{ $message }}</small>@enderror
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" @click="anulacion.abierto = false" class="inline-flex items-center justify-center rounded-lg border border-[#d9e2dc] bg-white px-4 py-2 text-sm font-bold text-[#17211b] hover:bg-[#f7faf8]">Cancelar</button>
                    <button type="submit" class="inline-flex items-center justify-center rounded-lg border border-red-200 bg-red-50 px-4 py-2 text-sm font-bold text-red-700 hover:bg-red-100">Anular termo</button>
                </div>
            </form>
        </div>
    </div>
    @endcan
</div>
