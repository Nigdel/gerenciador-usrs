{{--
    Tabla de un listado del cartorial.

    La usan los listados que no son bloques de campos (los documentos y los
    medios): mismas columnas, mismas filas, misma forma. Se extrae porque a
    partir del segundo listado la tabla se repetía entera en la plantilla, y
    una copia que se queda desactualizada es peor que ninguna.

    No lleva cabecera propia: el contenedor que la llama pone el título.
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
                <td class="px-4 py-3.5 text-[#17211b]">{{ $fila[$columna] ?? '—' }}</td>
                @endforeach
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
