@extends('layouts.app')

@section('title', 'Subsistemas')

@section('content')
    <section class="w-full max-w-5xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8 flex flex-col items-start justify-between gap-6 sm:flex-row">
            <div>
                <p class="mb-2 text-xs font-bold uppercase tracking-[0.08em] text-emerald-700">Gestão de acessos</p>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Subsistemas</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Administre las plataformas conectadas al gestor de usuarios.</p>
            </div>
            <a class="inline-flex min-h-11 items-center justify-center rounded-md bg-emerald-700 px-5 py-2.5 font-bold text-white no-underline transition hover:bg-emerald-800 focus:outline-none focus:ring-4 focus:ring-emerald-700/20" href="{{ route('subsystems.create') }}">Nuevo subsistema</a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ session('error') }}</div>
        @endif

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
                            <th class="px-4 py-3.5 font-bold">Slug</th>
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Proveedor de identidad</th>
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
                                <td class="px-4 py-3.5"><code class="rounded bg-emerald-50 px-1.5 py-1 text-xs text-emerald-800">{{ $subsystem->slug }}</code></td>
                                <td class="px-4 py-3.5"><span class="inline-flex rounded-full px-2 py-1 text-xs font-bold {{ $subsystem->activo ? 'bg-green-50 text-green-800' : 'bg-gray-100 text-gray-600' }}">{{ $subsystem->activo ? 'Activo' : 'Inactivo' }}</span></td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $subsystem->es_proveedor_identidad ? 'Sí' : 'No' }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $subsystem->accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex justify-start gap-3 whitespace-nowrap sm:justify-end">
                                    <a class="font-bold text-emerald-700 no-underline hover:text-emerald-900" href="{{ route('subsystems.show', $subsystem) }}">Ver</a>
                                    <a class="font-bold text-emerald-700 no-underline hover:text-emerald-900" href="{{ route('subsystems.edit', $subsystem) }}">Editar</a>
                                    <form action="{{ route('subsystems.destroy', $subsystem) }}" method="POST" onsubmit="return confirm('¿Eliminar este subsistema?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="font-bold text-red-700 hover:text-red-900" type="submit">Eliminar</button>
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
