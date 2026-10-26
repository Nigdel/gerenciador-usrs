@extends('layouts.app')

@section('title', 'Usuarios gestionados')

@php
    // Clases reutilizables para botones e inputs (un solo lugar para ajustar el estilo)
    $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
    $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
    $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
    $btnDanger = $btnBase.' border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus-visible:ring-red-500';
    $btnIcon = 'px-3'; // variante compacta para botones solo con icono
    $input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
@endphp

@section('content')
    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Listagem de Usuarios Gestionados</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Administra personas y sus cuentas en los subsistemas conectados.</p>
            </div>
            @can('create', [App\Models\GestorUser::class])
                <a class="{{ $btnPrimary }} shrink-0 self-start sm:self-auto" href="{{ route('gestor-users.create') }}">Novo</a>
            @endcan
        </div>

        {{-- Filtros --}}
        <form method="GET" action="{{ route('gestor-users.index') }}" class="mb-6 grid items-end gap-3 rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_200px_200px_auto]" role="search">
            <div>
                <label class="sr-only" for="filtro-q">Buscar usuarios</label>
                <input id="filtro-q" class="{{ $input }}" type="search" name="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Nombre, usuario, empresa o CPF">
            </div>
            <div>
                <label class="sr-only" for="filtro-estado">Estado</label>
                <select id="filtro-estado" class="{{ $input }}" name="estado">
                    <option value="">Todos los estados</option>
                    @foreach (\App\Enums\GestorUserStatus::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['estado'] ?? null) === $caso->value)>{{ ucfirst($caso->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="sr-only" for="filtro-subsistema">Subsistema</label>
                <select id="filtro-subsistema" class="{{ $input }}" name="subsistema">
                    <option value="">Todos los subsistemas</option>
                    @foreach ($subsistemas as $subsistema)
                        <option value="{{ $subsistema->slug }}" @selected(($filtros['subsistema'] ?? null) === $subsistema->slug)>{{ $subsistema->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2 sm:col-span-2 lg:col-span-1">
                <button class="{{ $btnPrimary }}" type="submit">Filtrar</button>
                @if (($filtros['q'] ?? '') !== '' || ($filtros['estado'] ?? '') !== '' || ($filtros['subsistema'] ?? '') !== '')
                    <a class="{{ $btnSecondary }}" href="{{ route('gestor-users.index') }}">Limpiar</a>
                @endif
            </div>
        </form>

        {{-- Listado --}}
        @if ($gestorUsers->isEmpty())
            <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-6 py-12 text-center text-[#68756d]">
                @if (request()->filled('q') || request()->filled('estado') || request()->filled('subsistema'))
                    <strong class="mb-1 block text-[#17211b]">Ningún usuario coincide con el filtro.</strong>
                    <span class="text-sm">Prueba con otro texto o quita algún criterio.</span>
                @else
                    <strong class="mb-1 block text-[#17211b]">No hay usuarios gestionados.</strong>
                    <span class="text-sm">Agrega el primero y selecciona dónde debe tener una cuenta.</span>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                        <tr>
                            <th scope="col" class="px-4 py-3.5 font-bold">Nombre</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">CPF</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Empresa</th>
                            <th scope="col" class="px-4 py-3.5 font-bold">Estado</th>
                            <th scope="col" class="px-4 py-3.5 text-center font-bold">Cuentas</th>
                            <th scope="col" class="px-4 py-3.5 font-bold"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#e8eee9]">
                        @foreach ($gestorUsers as $gestorUser)
                            <tr class="align-middle transition hover:bg-[#f7faf8]">
                                <td class="px-4 py-3.5 font-bold text-[#17211b]">
                                    <a class="hover:text-emerald-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ route('gestor-users.show', $gestorUser) }}" aria-label="Ver {{ $gestorUser->nombre_completo }}" title="Ver">{{ $gestorUser->nombre_completo }}</a>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3.5 font-mono text-[#17211b]">{{ $gestorUser->cpf }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->empresa }}</td>
                                <td class="px-4 py-3.5">
                                    @if ($gestorUser->estaDadoDeBaja())
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-red-200 bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700" title="Dado de baja">
                                            <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>De baja
                                        </span>
                                    @elseif ($gestorUser->estaSuspendido())
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-800" title="Gestor suspendido">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-500"></span>Suspendido
                                        </span>
                                        <span class="mt-1 block max-w-[18rem] text-xs leading-5 text-[#68756d]">
                                            @if ($gestorUser->motivo_suspension_gestor)
                                                {{ $gestorUser->motivo_suspension_gestor }}
                                            @endif
                                            @if ($gestorUser->suspendido_at)
                                                · desde {{ $gestorUser->suspendido_at->format('d/m/Y') }}
                                            @endif
                                            @if ($gestorUser->fin_suspension_gestor)
                                                · fin {{ $gestorUser->fin_suspension_gestor->format('d/m/Y') }}
                                            @endif
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">
                                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Activo
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-center font-mono font-bold text-[#17211b]">{{ $gestorUser->subsystem_accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="{{ $btnSecondary }} {{ $btnIcon }}" href="{{ route('gestor-users.show', $gestorUser) }}" aria-label="Ver {{ $gestorUser->nombre_completo }}" title="Ver"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg><span class="sr-only">Ver</span></a>
                                        @can('update', $gestorUser)
                                            <a class="{{ $btnPrimary }} {{ $btnIcon }}" href="{{ route('gestor-users.edit', $gestorUser) }}" aria-label="Editar {{ $gestorUser->nombre_completo }}" title="Editar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg><span class="sr-only">Editar</span></a>
                                        @endcan
                                        @can('delete', $gestorUser)
                                            <form action="{{ route('gestor-users.destroy', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Eliminar este usuario? Solo se puede eliminar si no tiene cuentas asociadas.');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="{{ $btnDanger }} {{ $btnIcon }}" type="submit" aria-label="Eliminar {{ $gestorUser->nombre_completo }}" title="Eliminar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($gestorUsers->hasPages())
                <div class="mt-6">{{ $gestorUsers->links() }}</div>
            @endif
        @endif
    </section>
@endsection