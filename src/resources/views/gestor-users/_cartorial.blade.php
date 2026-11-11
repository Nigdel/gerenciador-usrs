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
        @foreach ($cartorial as $bloque)
        <div class="rounded-xl border border-[#d9e2dc] bg-white">
            <div class="border-b border-[#d9e2dc] bg-[#f7faf8] px-4 py-3">
                <h3 class="font-bold text-[#17211b]">{{ $bloque['titulo'] }}</h3>
                @if (filled($bloque['descripcion']))
                <p class="mt-0.5 text-xs text-[#68756d]">{{ $bloque['descripcion'] }}</p>
                @endif
            </div>

            <dl class="grid gap-px bg-[#e8eee9] sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($bloque['campos'] as $campo)
                <div class="bg-white px-4 py-3">
                    <dt class="text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">{{ $campo['etiqueta'] }}</dt>
                    <dd class="mt-0.5 break-words text-[#17211b] {{ $campo['valor'] === null ? 'text-[#a3b0a8]' : '' }}">
                        {{ $campo['valor'] ?? 'No informado' }}
                    </dd>
                </div>
                @endforeach
            </dl>
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
                Documentos exigidos al gestor y en qué situación está cada uno.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                    <tr>
                        @foreach ($cartorialDocumentos['columnas'] as $columna)
                        <th scope="col" class="px-4 py-3.5 font-bold">{{ $columna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e8eee9]">
                    @foreach ($cartorialDocumentos['filas'] as $fila)
                    <tr class="align-middle transition hover:bg-[#f7faf8]">
                        @foreach ($cartorialDocumentos['columnas'] as $columna)
                        <td class="px-4 py-3.5 text-[#17211b]">{{ $fila[$columna] ?? '—' }}</td>
                        @endforeach
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>
