{{--
    Tabla de un listado del cartorial.

    La usan los listados que no son bloques de campos (los documentos y los
    medios): mismas columnas, mismas filas, misma forma. Se extrae porque a
    partir del segundo listado la tabla se repetía entera en la plantilla, y
    una copia que se queda desactualizada es peor que ninguna.

    No lleva cabecera propia: el contenedor que la llama pone el título.

    En los medios, el nombre de la columna "Descrição" abre la ficha del
    equipo: los datos no vienen en la página (son muchos y la ficha se abre en
    cada consulta), así que se piden a GLPI bajo demanda y se muestran en un
    modal.
--}}
<div class="overflow-x-auto">
    <table class="min-w-full border-collapse text-left text-sm">
        <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
            <tr>
                @foreach ($columnas as $columna)
                <th scope="col" class="px-4 py-3.5 font-bold">{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody class="divide-y divide-[#e8eee9]">
            @foreach ($filas as $fila)
            <tr class="align-middle transition hover:bg-[#f7faf8]">
                @foreach ($columnas as $columna)
                @php($enlace = $fila['enlace'] ?? null)
                @php($medio = $fila['medio'] ?? null)
                @php($termo = $fila['termo'] ?? null)
                <td class="px-4 py-3.5 text-[#17211b]">
                    @if ($medio && $columna === 'Descrição')
                    {{-- El nombre del medio abre su ficha: los datos se piden
                         a GLPI en el momento, porque son muchos y la ficha
                         se abre en cada consulta. --}}
                    <button type="button"
                        @click="abrir(@js($medio['tipo']), @js($medio['id']))"
                        class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                        {{ $fila[$columna] ?? '—' }}
                    </button>
                    @elseif ($medio && $columna === 'Termo')
                    {{-- El termo cuelga del medio: aquí se ve si ya está
                         emitido y, si no lo está, se abre el formulario. --}}
                    @if (! empty($termo) && $termo['vigente'])
                    <a href="{{ $termo['pdf'] }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800 hover:bg-emerald-100">
                        {{ $termo['etiqueta'] }}
                    </a>
                    @else
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! empty($termo))
                        <span class="rounded-full border border-[#d9e2dc] bg-[#f7faf8] px-2.5 py-0.5 text-xs font-bold text-[#68756d]">{{ $termo['etiqueta'] }}</span>
                        @endif
                        @can('generarTermo', $gestorUser)
                        <button type="button"
                            @click="abrirTermo(@js($medio['tipo']), @js($medio['id']))"
                            class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                            {{ empty($termo) ? 'Gerar termo' : 'Novo termo' }}
                        </button>
                        @else
                        <span class="text-[#a3b0a8]">Sem termo</span>
                        @endcan
                    </div>
                    @endif
                    @else
                    {{ $fila[$columna] ?? '—' }}
                    @endif
                </td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
