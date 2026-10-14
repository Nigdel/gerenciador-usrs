@extends('layouts.app')

@section('title', 'Subsistemas')

@section('content')
    @php
        // Clases reutilizables basadas en la identidad visual de gestor-users/show
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $th = 'px-4 py-3.5 font-bold';
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8 flex flex-col items-start justify-between gap-6 sm:flex-row sm:items-end">
            <div>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Subsistemas</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Administre las plataformas conectadas al gestor de usuarios.</p>
            </div>
            @can('create', [App\Models\Subsystem::class])
                <a class="{{ $btnPrimary }}" href="{{ route('subsystems.create') }}">Nuevo subsistema</a>
            @endcan
        </div>

        {{-- Filtro de búsqueda --}}
        <form method="GET" action="{{ route('subsystems.index') }}" class="mb-8 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto]" role="search">
            <div>
                <label class="mb-1 block text-sm font-bold text-[#17211b]" for="filtro-subsistemas">Buscar subsistemas</label>
                <input id="filtro-subsistemas" name="q" type="search" class="{{ $input }}" value="{{ $busqueda }}" placeholder="Nombre o slug del subsistema">
            </div>
            <div class="flex gap-2">
                <button class="{{ $btnPrimary }}" type="submit">Filtrar</button>
                @if ($busqueda !== '')
                    <a class="{{ $btnSecondary }}" href="{{ route('subsystems.index') }}">Limpiar</a>
                @endif
            </div>
        </form>

        @if ($subsystems->isEmpty())
            <div class="grid gap-2 rounded-xl border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-6 py-11 text-center text-[#68756d]">
                @if ($busqueda !== '')
                    <strong class="text-[#17211b]">Ningún subsistema coincide con la búsqueda.</strong>
                    <span>Prueba con otro texto.</span>
                @else
                    <strong class="text-[#17211b]">No hay subsistemas registrados.</strong>
                    <span>Agrega el primero para comenzar a gestionar integraciones.</span>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left text-sm">
                    <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                        <tr>
                            <th scope="col" class="{{ $th }}">Nombre</th>
                            <th scope="col" class="{{ $th }}">Estado</th>
                            <th scope="col" class="{{ $th }}">Último Test</th>
                            <th scope="col" class="{{ $th }}">Cuentas</th>
                            <th scope="col" class="{{ $th }}"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#e8eee9]">
                        @foreach ($subsystems as $subsystem)
                            <tr class="align-middle transition hover:bg-[#f7faf8]">
                                <td class="px-4 py-3.5">
                                    <strong class="font-bold text-[#17211b]">{{ $subsystem->nombre }}</strong>
                                    @if ($subsystem->descripcion)
                                        <small class="mt-1 block max-w-[280px] text-[#68756d]">{{ Str::limit($subsystem->descripcion, 70) }}</small>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs font-bold {{ $subsystem->activo ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-100 text-gray-600' }}">
                                        {{ $subsystem->activo ? 'Activo' : 'Inactivo' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-[#17211b]">
                                    <div class="flex flex-col">
                                        @if ($subsystem->last_connection_test_success === null)
                                            <span class="text-[#a3b0a8]">Sin pruebas</span>
                                        @elseif ($subsystem->last_connection_test_success)
                                            <span class="text-emerald-700 font-semibold">✓ Exitoso</span>
                                        @else
                                            <span class="text-red-700 font-semibold">✗ Fallido</span>
                                        @endif
                                        @if($subsystem->last_connection_test_at)
                                            <small class="text-xs text-[#68756d]">{{ $subsystem->last_connection_test_at->format('d/m/y H:i') }}</small>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 font-mono text-[#17211b]">{{ $subsystem->accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[#d9e2dc] bg-white text-[#17211b] transition hover:bg-[#f7faf8] focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" href="{{ route('subsystems.show', $subsystem) }}" aria-label="Ver {{ $subsystem->nombre }}" title="Ver">
                                            <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg>
                                            <span class="sr-only">Ver</span>
                                        </a>
                                        @if (filled($subsystem->access_url))
                                            <a class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-700 bg-emerald-700 text-white transition hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus:ring-emerald-600" href="{{ $subsystem->access_url }}" target="_blank" rel="noopener noreferrer" aria-label="Abrir plataforma de {{ $subsystem->nombre }}" title="Abrir {{ $subsystem->nombre }}">
                                                <svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v7H3V3h7"/></svg>
                                            </a>
                                        @endif
                                        @can('update', $subsystem)
                                            <a class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-700 transition hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus:ring-emerald-600" href="{{ route('subsystems.edit', $subsystem) }}" aria-label="Editar {{ $subsystem->nombre }}" title="Editar">
                                                <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg>
                                                <span class="sr-only">Editar</span>
                                            </a>
                                        @endcan
                                        @can('delete', $subsystem)
                                            <form class="inline-flex" action="{{ route('subsystems.destroy', $subsystem) }}" method="POST" onsubmit="return confirm('¿Eliminar este subsistema?');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-700 transition hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus:ring-emerald-600" type="submit" aria-label="Eliminar {{ $subsystem->nombre }}" title="Eliminar">
                                                    <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-trash"></use></svg>
                                                    <span class="sr-only">Eliminar</span>
                                                </button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($subsystems->hasPages())
                <div class="mt-5">{{ $subsystems->links() }}</div>
            @endif
        @endif
    </section>
@endsection
