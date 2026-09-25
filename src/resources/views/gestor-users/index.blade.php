@extends('layouts.app')

@section('title', 'Usuarios gestionados')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Gestión de accesos externos</p>
                <h1 id="page-title">Usuarios gestionados</h1>
                <p>Administra personas y sus cuentas en los subsistemas conectados.</p>
            </div>
            <a class="button button-primary" href="{{ route('gestor-users.create') }}">Nuevo usuario</a>
        </div>

        @if ($gestorUsers->isEmpty())
            <div class="border border-dashed border-[#d9e2dc] px-6 py-11 text-center text-[#68756d]">
                <strong class="block text-[#17211b]">No hay usuarios gestionados.</strong>
                <span>Agrega el primero y selecciona dónde debe tener una cuenta.</span>
            </div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Nombre</th>
                            <th class="px-4 py-3.5 font-bold">CPF</th>
                            <th class="px-4 py-3.5 font-bold">Empresa</th>
                            <th class="px-4 py-3.5 font-bold">Cuentas</th>
                            <th class="px-4 py-3.5 font-bold"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($gestorUsers as $gestorUser)
                            <tr class="border-t border-[#e8eee9] align-middle">
                                <td class="px-4 py-3.5 font-bold text-[#17211b]">{{ $gestorUser->nombre_completo }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->cpf }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->empresa }}</td>
                                <td class="px-4 py-3.5 text-[#17211b]">{{ $gestorUser->subsystem_accounts_count }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex justify-start gap-2 whitespace-nowrap sm:justify-end">
                                        <a class="button button-secondary" href="{{ route('gestor-users.show', $gestorUser) }}" aria-label="Ver {{ $gestorUser->nombre_completo }}" title="Ver"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-eye"></use></svg><span class="sr-only">Ver</span></a>
                                        <a class="button button-primary" href="{{ route('gestor-users.edit', $gestorUser) }}" aria-label="Editar {{ $gestorUser->nombre_completo }}" title="Editar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg><span class="sr-only">Editar</span></a>
                                        <form action="{{ route('gestor-users.destroy', $gestorUser) }}" method="POST" onsubmit="return confirm('¿Eliminar este usuario? Solo se puede eliminar si no tiene cuentas asociadas.');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="button button-danger" type="submit" aria-label="Eliminar {{ $gestorUser->nombre_completo }}" title="Eliminar"><svg class="h-4 w-4" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
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