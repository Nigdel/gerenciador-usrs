@extends('layouts.app')

@section('title', 'Operações de Provisionamento')

@section('content')
    @php
        // Clases reutilizables (un solo lugar para ajustar el estilo)
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $btnIcon = 'px-3';
        $input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $hayFiltros = request()->anyFilled(['usuario', 'estado', 'tipo', 'fecha_inicio', 'fecha_fim']);
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8">    
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Operações de Provisionamento</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Histórico detalhado de todas as ações realizadas nos subsistemas.</p>
        </div>

        {{-- Filtros --}}
        <form method="GET" action="{{ route('operaciones.index') }}" class="mb-6 grid items-end gap-3 rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.6fr)_repeat(4,minmax(0,1fr))_auto]" role="search">
            <div class="sm:col-span-2 lg:col-span-1">
                <label class="sr-only" for="filtro-usuario">Buscar usuário</label>
                <input id="filtro-usuario" class="{{ $input }}" type="search" name="usuario" value="{{ $filtros['usuario'] ?? '' }}" placeholder="Nome, CPF ou login do usuário">
            </div>
            <div>
                <label class="sr-only" for="filtro-estado">Estado</label>
                <select id="filtro-estado" class="{{ $input }}" name="estado">
                    <option value="">Todos os estados</option>
                    @foreach (\App\Enums\OperationStatus::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['estado'] ?? null) === $caso->value)>{{ $caso->etiqueta() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="sr-only" for="filtro-tipo">Tipo</label>
                <select id="filtro-tipo" class="{{ $input }}" name="tipo">
                    <option value="">Todos os tipos</option>
                    @foreach (\App\Enums\OperationType::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['tipo'] ?? null) === $caso->value)>{{ $caso->etiqueta() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-[0.06em] text-[#68756d] lg:sr-only" for="filtro-inicio">Desde</label>
                <input id="filtro-inicio" class="{{ $input }}" type="date" name="fecha_inicio" value="{{ $filtros['fecha_inicio'] ?? '' }}">
            </div>
            <div>
                <label class="mb-1 block text-xs font-bold uppercase tracking-[0.06em] text-[#68756d] lg:sr-only" for="filtro-fim">Até</label>
                <input id="filtro-fim" class="{{ $input }}" type="date" name="fecha_fim" value="{{ $filtros['fecha_fim'] ?? '' }}">
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <button class="{{ $btnPrimary }}" type="submit">Filtrar</button>
                @if ($hayFiltros)
                    <a class="{{ $btnSecondary }}" href="{{ route('operaciones.index') }}">Limpiar</a>
                @endif
            </div>
        </form>

        {{-- Listado --}}
        @if ($operaciones->isEmpty())
            <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-6 py-12 text-center text-[#68756d]">
                @if ($hayFiltros)
                    <strong class="mb-1 block text-[#17211b]">Nenhuma operação coincide com o filtro.</strong>
                    <span class="text-sm">Tente outros critérios ou limpe a busca.</span>
                @else
                    <strong class="mb-1 block text-[#17211b]">Não há operações registradas.</strong>
                    <span class="text-sm">As ações de provisionamento aparecerão aqui.</span>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                        <tr>
                            <th scope="col" class="px-4 py-3.5 font-bold">Data</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Usuário</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Tipo</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Estado</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Ator</th>
                            <th scope="col" class="px-4 py-3.5 font-bold"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#e8eee9]">
                        @foreach ($operaciones as $operacion)
                            @php
                                [$badge, $dot] = match ($operacion->estado) {
                                    \App\Enums\OperationStatus::Fallida => ['border-red-200 bg-red-50 text-red-700', 'bg-red-500'],
                                    \App\Enums\OperationStatus::Completada => ['border-emerald-200 bg-emerald-50 text-emerald-700', 'bg-emerald-500'],
                                    \App\Enums\OperationStatus::EnCurso => ['border-blue-200 bg-blue-50 text-blue-700', 'bg-blue-500'],
                                    default => ['border-[#d9e2dc] bg-[#f7faf8] text-[#68756d]', 'bg-[#a3b0a8]'],
                                };
                            @endphp
                            <tr class="align-middle transition hover:bg-[#f7faf8]">
                                <td class="whitespace-nowrap px-4 py-3.5 font-mono text-[#17211b]">
                                    {{ $operacion->iniciada_at ? $operacion->iniciada_at->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3.5 font-bold text-[#17211b]">
                                    @if ($operacion->usuario)
                                        <a class="hover:text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ route('gestor-users.show', $operacion->usuario) }}" title="Ver usuário">{{ $operacion->usuario->nombre_completo }}</a>
                                    @else
                                        <span class="font-normal text-[#a3b0a8]">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $operacion->tipo->etiqueta() }}</td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-bold {{ $badge }}" title="{{ $operacion->estado->etiqueta() }}">
                                        <span class="h-1.5 w-1.5 rounded-full {{ $dot }}"></span>{{ $operacion->estado->etiqueta() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-[#68756d]">{{ $operacion->autor() }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        @if ($operacion->usuario)
                                            <a class="{{ $btnSecondary }} {{ $btnIcon }}" href="{{ route('gestor-users.show', $operacion->usuario) }}" aria-label="Ver usuário" title="Ver ficha">
                                                <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg>
                                                <span class="sr-only">Ver usuário</span>
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($operaciones->hasPages())
                <div class="mt-6">{{ $operaciones->links() }}</div>
            @endif
        @endif
    </section>
@endsection