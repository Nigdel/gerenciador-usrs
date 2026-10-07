@extends('layouts.app')

@section('title', 'Usuarios gestionados')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <a class="eyebrow" href="{{ route('home') }}">Home</a>
                <h1 id="page-title">Listagem de Usuarios Gestionados</h1>
                <p>Administra personas y sus cuentas en los subsistemas conectados.</p>
            </div>
            @can('create', [App\Models\GestorUser::class])
                <a class="button button-primary" href="{{ route('gestor-users.create') }}">Novo</a>
            @endcan
        </div>

        <form method="GET" action="{{ route('gestor-users.index') }}" class="mb-5 grid items-end gap-3 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto]" role="search">
            <div class="field">
                <label class="sr-only" for="filtro-q">Buscar usuarios</label>
                <input id="filtro-q" type="search" name="q" value="{{ $filtros['q'] ?? '' }}" placeholder="Nombre, usuario, empresa o CPF">
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-estado">Estado</label>
                <select id="filtro-estado" name="estado">
                    <option value="">Todos los estados</option>
                    @foreach (\App\Enums\GestorUserStatus::cases() as $caso)
                        <option value="{{ $caso->value }}" @selected(($filtros['estado'] ?? null) === $caso->value)>{{ ucfirst($caso->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="field">
                <label class="sr-only" for="filtro-subsistema">Subsistema</label>
                <select id="filtro-subsistema" name="subsistema">
                    <option value="">Todos los subsistemas</option>
                    @foreach ($subsistemas as $subsistema)
                        <option value="{{ $subsistema->slug }}" @selected(($filtros['subsistema'] ?? null) === $subsistema->slug)>{{ $subsistema->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex gap-2">
                <button class="button button-primary" type="submit">Filtrar</button>
                @if (($filtros['q'] ?? '') !== '' || ($filtros['estado'] ?? '') !== '' || ($filtros['subsistema'] ?? '') !== '')
                    <a class="button button-secondary" href="{{ route('gestor-users.index') }}">Limpiar</a>
                @endif
            </div>
        </form>

        @if ($gestorUsers->isEmpty())
            <div class="border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                @if (request()->filled('q') || request()->filled('estado') || request()->filled('subsistema'))
                    <strong class="block text-[#17211b]">Ningún usuario coincide con el filtro.</strong>
                    <span>Prueba con otro texto o quita algún criterio.</span>
                @else
                    <strong class="block text-[#17211b]">No hay usuarios gestionados.</strong>
                    <span>Agrega el primero y selecciona dónde debe tener una cuenta.</span>
                @endif
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Nombre</th>
                            <th class="px-4 py-3.5 font-bold">CPF</th>
                            <th class="px-4 py-3.5 font-bold">Empresa</th>
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Cuentas</th>
                            <th class="px-4 py-3.5 font-bold"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($gestorUsers as $gestorUser)
                            <tr class="border-t border-[#e8eee9] align-middle">
                                <td class="px-4 py-3.5 font-bold text-[#17211b]"><a  href="{{ route('gestor-users.show', $gestorUser) }}" aria-label="Ver {{ $gestorUser->nombre_completo }}" title="Ver">{{ $gestorUser->nombre_completo }}</a></td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->cpf }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->empresa }}</td>
                                <td class="px-4 py-3.5">
                                    @if ($gestorUser->estaDadoDeBaja())
                                        <span class="account-status-deleted" title="Dado de baja">De baja</span>
                                    @else
                                        <span class="text-[#68756d]">Activo</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->subsystem_accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="button button-secondary" href="{{ route('gestor-users.show', $gestorUser) }}" aria-label="Ver {{ $gestorUser->nombre_completo }}" title="Ver"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg><span class="sr-only">Ver</span></a>
                                        @can('update', $gestorUser)
                                            <a class="button button-primary" href="{{ route('gestor-users.edit', $gestorUser) }}" aria-label="Editar {{ $gestorUser->nombre_completo }}" title="Editar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg><span class="sr-only">Editar</span></a>
                                        @endcan
                                        @can('delete', $gestorUser)
                                            <form action="{{ route('gestor-users.destroy', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Eliminar este usuario? Solo se puede eliminar si no tiene cuentas asociadas.');">
                                                @csrf
                                                @method('DELETE')
                                                <button class="button button-danger" type="submit" aria-label="Eliminar {{ $gestorUser->nombre_completo }}" title="Eliminar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
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
                <div class="mt-5">{{ $gestorUsers->links() }}</div>
            @endif
        @endif
    </section>
@endsection