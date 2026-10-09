@extends('layouts.app')

@section('title', 'Operações de Provisionamento')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <a class="eyebrow" href="{{ route('home') }}">Home</a>
                <h1 id="page-title">Operações de Provisionamento</h1>
                <p>Histórico detalhado de todas as ações realizadas nos subsistemas.</p>
            </div>
        </div>

        <form method="GET" action="{{ route('operaciones.index') }}" class="mb-5 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto_auto]" role="search">
            <div class="field">
                <label class="sr-only" for="filtro-usuario">Buscar usuário</label>
                <input id="filtro-usuario" type="search" name="usuario" value="{{ $filtros['usuario'] ?? '' }}" placeholder="Nome, CPF ou login do usuário">
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-estado">Estado</label>
                <select id="filtro-estado" name="estado">
                    <option value="">Todos os estados</option>
                    @foreach (\App\Enums\OperationStatus::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['estado'] ?? null) === $caso->value)>{{ $caso->etiqueta() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-tipo">Tipo</label>
                <select id="filtro-tipo" name="tipo">
                    <option value="">Todos os tipos</option>
                    @foreach (\App\Enums\OperationType::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['tipo'] ?? null) === $caso->value)>{{ $caso->etiqueta() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-inicio">Desde</label>
                <input id="filtro-inicio" type="date" name="fecha_inicio" value="{{ $filtros['fecha_inicio'] ?? '' }}">
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-fim">Até</label>
                <input id="filtro-fim" type="date" name="fecha_fim" value="{{ $filtros['fecha_fim'] ?? '' }}">
            </div>
            <div class="flex gap-2">
                <button class="button button-primary" type="submit">Filtrar</button>
                @if (request()->anyFilled(['usuario', 'estado', 'tipo', 'fecha_inicio', 'fecha_fim']))
                    <a class="button button-secondary" href="{{ route('operaciones.index') }}">Limpiar</a>
                @endif
            </div>
        </form>

        @if ($operaciones->isEmpty())
            <div class="border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                @if (request()->anyFilled(['usuario', 'estado', 'tipo', 'fecha_inicio', 'fecha_fim']))
                    <strong class="block text-[#17211b]">Nenhuma operação coincide com o filtro.</strong>
                    <span>Tente outros critérios ou limpe a busca.</span>
                @else
                    <strong class="block text-[#17211b]">Não há operações registradas.</strong>
                    <span>As ações de provisionamento aparecerão aqui.</span>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Data</th>
                            <th class="px-4 py-3.5 font-bold">Usuário</th>
                            <th class="px-4 py-3.5 font-bold">Tipo</th>
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Ator</th>
                            <th class="px-4 py-3.5 font-bold"><span class="sr-only">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($operaciones as $operacion)
                            <tr class="border-t border-[#e8eee9] align-middle">
                                <td class="px-4 py-3.5 text-[#17211b] whitespace-nowrap">
                                    {{ $operacion->iniciada_at ? $operacion->iniciada_at->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3.5 font-bold text-[#17211b]">
                                    <a href="{{ route('gestor-users.show', $operacion->usuario) }}" title="Ver usuário">
                                        {{ $operacion->usuario->nombre_completo }}
                                    </a>
                                </td>
                                <td class="px-4 py-3.5 text-[#17211b]">
                                    {{ $operacion->tipo->etiqueta() }}
                                </td>
                                <td class="px-4 py-3.5">
                                    @php
                                        $statusClass = match($operacion->estado) {
                                            \App\Enums\OperationStatus::Fallida => 'account-status-deleted',
                                            \App\Enums\OperationStatus::Completada => 'text-green-600',
                                            \App\Enums\OperationStatus::EnCurso => 'text-blue-600',
                                            default => 'text-[#68756d]',
                                        };
                                    @endphp
                                    <span class="{{ $statusClass }}" title="{{ $operacion->estado->etiqueta() }}">
                                        {{ $operacion->estado->etiqueta() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-[#68756d]">
                                    {{ $operacion->autor() }}
                                </td>
                                <td class="px-4 py-3.5">
                                    <div class="flex justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="button button-secondary" href="{{ route('gestor-users.show', $operacion->usuario) }}" title="Ver ficha">
                                            <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg>
                                            <span class="sr-only">Ver usuário</span>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($operaciones->hasPages())
                <div class="mt-5">{{ $operaciones->links() }}</div>
            @endif
        @endif
    </section>
@endsection
