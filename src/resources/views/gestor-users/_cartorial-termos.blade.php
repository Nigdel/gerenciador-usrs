{{--
    Historial de termos del gestor.

    El termo no se edita: lo que cambia es su estado y los PDF que se le
    adjuntan. Las acciones disponibles dependen de ese estado, que las decide
    el enum TermoEstado: la vista no repite las reglas de negocio.
--}}
@php
$estadoClase = [
    'generado' => 'border-[#d9e2dc] bg-[#f7faf8] text-[#68756d]',
    'enviado' => 'border-amber-200 bg-amber-50 text-amber-700',
    'firmado' => 'border-emerald-200 bg-emerald-50 text-emerald-800',
    'anulado' => 'border-red-200 bg-red-50 text-red-700',
];
@endphp

<div class="overflow-x-auto">
    <table class="min-w-full border-collapse text-left text-sm">
        <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
            <tr>
                <th scope="col" class="px-4 py-3.5 font-bold">Equipamento</th>
                <th scope="col" class="px-4 py-3.5 font-bold">Tipo</th>
                <th scope="col" class="px-4 py-3.5 font-bold">Situação</th>
                <th scope="col" class="px-4 py-3.5 font-bold">Gerado em</th>
                <th scope="col" class="px-4 py-3.5 font-bold">Arquivos</th>
                <th scope="col" class="px-4 py-3.5 font-bold"><span class="sr-only">Ações</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-[#e8eee9]">
            @foreach ($termos as $termo)
            <tr class="align-top transition hover:bg-[#f7faf8]">
                <td class="px-4 py-3.5 text-[#17211b]">
                    <span class="block font-bold">{{ $termo->item_name ?: 'Equipamento sem nome' }}</span>
                    <span class="block font-mono text-xs text-[#68756d]">{{ $termo->item_type }} #{{ $termo->item_id }}@if ($termo->item_serial) · {{ $termo->item_serial }}@endif</span>
                    @if ($termo->estado->value === 'anulado' && $termo->motivo_anulacion)
                    <span class="mt-1 block text-xs text-red-700">Motivo: {{ $termo->motivo_anulacion }}</span>
                    @endif
                </td>
                <td class="px-4 py-3.5 text-[#17211b]">{{ $termo->tipo->etiqueta() }}</td>
                <td class="px-4 py-3.5">
                    <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-bold {{ $estadoClase[$termo->estado->value] ?? '' }}">
                        {{ $termo->estado->etiqueta() }}
                    </span>
                </td>
                <td class="px-4 py-3.5 text-[#17211b]">{{ $termo->generado_at?->format('d/m/Y H:i') ?: '—' }}</td>
                <td class="px-4 py-3.5">
                    <div class="flex flex-col items-start gap-1">
                        @if ($termo->pdf_generado)
                        <a href="{{ route('gestor-users.termos.pdf', [$gestorUser, $termo]) }}"
                            target="_blank"
                            rel="noopener"
                            class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">PDF gerado</a>
                        @endif
                        @if ($termo->pdf_firmado)
                        <a href="{{ route('gestor-users.termos.firmado.pdf', [$gestorUser, $termo]) }}"
                            target="_blank"
                            rel="noopener"
                            class="font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-2 hover:text-emerald-900">PDF firmado</a>
                        @endif
                        @if (! $termo->pdf_generado && ! $termo->pdf_firmado)
                        <span class="text-[#a3b0a8]">—</span>
                        @endif
                    </div>
                </td>
                <td class="px-4 py-3.5">
                    <div class="flex flex-wrap items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                        @if ($termo->estado->value === 'generado')
                        @can('actualizarTermo', $gestorUser)
                        <form action="{{ route('gestor-users.termos.enviado', [$gestorUser, $termo]) }}" method="POST">
                            @csrf
                            <button type="submit" class="rounded-md border border-[#d9e2dc] bg-white px-2.5 py-1 text-xs font-bold text-[#17211b] transition hover:bg-[#f7faf8]">Marcar enviado</button>
                        </form>
                        @endcan
                        @endif

                        @if ($termo->permiteSubirFirmado())
                        @can('actualizarTermo', $gestorUser)
                        <button type="button"
                            @click="abrirFirmado({{ $termo->id }}, @js(route('gestor-users.termos.firmado', [$gestorUser, $termo])))"
                            class="rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800 transition hover:bg-emerald-100">Anexar firmado</button>
                        @endcan
                        @endif

                        @if ($termo->permiteAnular())
                        @can('anularTermo', $gestorUser)
                        <button type="button"
                            @click="abrirAnulacion({{ $termo->id }}, @js(route('gestor-users.termos.anular', [$gestorUser, $termo])))"
                            class="rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-bold text-red-700 transition hover:bg-red-100">Anular</button>
                        @endcan
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
