{{--
    Cartorial del gestor.

    Los bloques los arma GestorUserCartorialService: esta plantilla solo los
    pinta. Añadir un bloque nuevo (un proceso, un documento) es añadir una
    entrada a la lista del servicio, no tocar aquí.

    No lleva título propio: el panel ya está etiquetado por su pestaña
    (aria-labelledby), y repetir el nombre "Cartorial" dentro del panel solo
    añadiría ruido.
--}}
<div id="cartorial" class="pt-6">
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

                {{-- Las clases van escritas enteras: Tailwind las compila
                     escaneando el archivo, y una clase construida por
                     concatenación no existe en el CSS final. --}}
                <dl class="grid gap-px bg-[#e8eee9] sm:grid-cols-2 {{ ($panel['ancho'] ?? 'mitad') === 'completo' ? 'lg:grid-cols-4' : 'lg:grid-cols-2' }}">
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
                @if (! empty($panel['tabla']['filas']))
                <div class="border-t border-[#d9e2dc]">
                    <div class="px-4 py-3">
                        <h4 class="text-sm font-bold text-[#17211b]">{{ $panel['tabla']['titulo'] }}</h4>
                        @if (filled($panel['tabla']['descripcion']))
                        <p class="mt-0.5 text-xs text-[#68756d]">{{ $panel['tabla']['descripcion'] }}</p>
                        @endif
                    </div>

                    @include('gestor-users._cartorial-tabla', [
                        'columnas' => $panel['tabla']['columnas'],
                        'filas' => $panel['tabla']['filas'],
                    ])
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
</div>
