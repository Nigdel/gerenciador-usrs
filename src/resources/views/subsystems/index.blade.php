@extends('layouts.app')

@section('title', 'Subsistemas')

@section('content')
    <section class="w-full max-w-5xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8 flex flex-col items-start justify-between gap-6 sm:flex-row">
            <div>
                <a class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-emerald-700" href="{{ route('home') }}">Gestão de acessos</a>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Subsistemas</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Administre las plataformas conectadas al gestor de usuarios.</p>
            </div>
            <a class="inline-flex min-h-11 items-center justify-center rounded-md bg-emerald-700 px-5 py-2.5 font-bold text-white no-underline transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-700/20" href="{{ route('subsystems.create') }}">Nuevo subsistema</a>
        </div>

        @if ($subsystems->isEmpty())
            <div class="grid gap-2 border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                <strong class="text-[#17211b]">No hay subsistemas registrados.</strong>
                <span>Agrega el primero para comenzar a gestionar integraciones.</span>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Nombre</th>
                            
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Ultimo Test</th>                            
                            <th class="px-4 py-3.5 font-bold">Cuentas</th>
                            <th class="px-4 py-3.5 font-bold"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subsystems as $subsystem)
                            <tr class="border-t border-[#e8eee9] align-middle">
                                <td class="px-4 py-3.5">
                                    <strong class="font-bold text-[#17211b]">{{ $subsystem->nombre }}</strong>
                                    @if ($subsystem->descripcion)
                                        <small class="mt-1 block max-w-[280px] text-[#68756d]">{{ Str::limit($subsystem->descripcion, 70) }}</small>
                                    @endif
                                </td>
                              
                                <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2 py-1 text-xs font-bold {{ $subsystem->activo ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-600' }}">{{ $subsystem->activo ? 'Activo' : 'Inactivo' }}</span></td>
                                <td class="px-4 py-3.5 text-[#17211b]">
                                @if ($subsystem->last_connection_test_success === null)
                                    <span class="text-[#68756d]">Sem probas realizadas</span>
                                @elseif ($subsystem->last_connection_test_success)
                                    <span class="text-[#1f5d49] font-semibold">✓ ok</span>
                                @else
                                    <span class="text-red-700 font-semibold">✗ Fail</span>
                                @endif
                                    {{ $subsystem->last_connection_test_at ? $subsystem->last_connection_test_at->format('d/m/y H:i') : '-' }}</td>                                
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $subsystem->accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex flex-wrap justify-start gap-3 whitespace-nowrap sm:justify-end">
                                        <a class="inline-flex min-h-9 items-center justify-center rounded-md bg-sky-600 px-3 py-2 text-sm font-bold text-white no-underline transition hover:bg-sky-700 focus:outline-none focus:ring-4 focus:ring-sky-600/25" href="{{ route('subsystems.show', $subsystem) }}" aria-label="Ver {{ $subsystem->nombre }}" title="Ver"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg><span class="sr-only">Ver</span></a>
                                        @if (filled($subsystem->access_url))
                                            <a class="inline-flex min-h-9 items-center justify-center gap-2 rounded-md border border-emerald-700 bg-emerald-700 px-3 py-2 text-sm font-bold text-white no-underline shadow-sm transition hover:bg-emerald-800 hover:text-white focus:outline-none focus:ring-4 focus:ring-emerald-700/20" href="{{ $subsystem->access_url }}" target="_blank" rel="noopener noreferrer" aria-label="Abrir plataforma de {{ $subsystem->nombre }}" title="Abrir {{ $subsystem->nombre }}">
                                                <svg class="h-4 w-4" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v7H3V3h7"/></svg>
                                                
                                            </a>
                                        @endif
                                        <a class="inline-flex min-h-9 items-center justify-center rounded-md bg-amber-500 px-3 py-2 text-sm font-bold text-white no-underline transition hover:bg-amber-600 focus:outline-none focus:ring-4 focus:ring-amber-500/25" href="{{ route('subsystems.edit', $subsystem) }}" aria-label="Editar {{ $subsystem->nombre }}" title="Editar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg><span class="sr-only">Editar</span></a>
                                        <form class="inline-flex" action="{{ route('subsystems.destroy', $subsystem) }}" method="POST" onsubmit="return confirm('¿Eliminar este subsistema?');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="inline-flex min-h-9 items-center justify-center rounded-md bg-red-600 px-3 py-2 text-sm font-bold text-white transition hover:bg-red-700 focus:outline-none focus:ring-4 focus:ring-red-600/25" type="submit" aria-label="Eliminar {{ $subsystem->nombre }}" title="Eliminar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
