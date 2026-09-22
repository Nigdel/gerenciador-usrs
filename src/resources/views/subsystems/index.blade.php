@extends('layouts.app')

@section('title', 'Subsistemas')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Gestão de acessos</p>
                <h1 id="page-title">Subsistemas</h1>
                <p>Administre las plataformas conectadas al gestor de usuarios.</p>
            </div>
            <a class="button button-primary" href="{{ route('subsystems.create') }}">Nuevo subsistema</a>
        </div>

        @if (session('success'))
            <div class="feedback feedback-success" role="status">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="feedback feedback-error" role="alert">{{ session('error') }}</div>
        @endif

        @if ($subsystems->isEmpty())
            <div class="empty-state">
                <strong>No hay subsistemas registrados.</strong>
                <span>Agrega el primero para comenzar a gestionar integraciones.</span>
            </div>
        @else
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Slug</th>
                            <th>Estado</th>
                            <th>Proveedor de identidad</th>
                            <th>Cuentas</th>
                            <th class="actions-column"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($subsystems as $subsystem)
                            <tr>
                                <td>
                                    <strong>{{ $subsystem->nombre }}</strong>
                                    @if ($subsystem->descripcion)
                                        <small>{{ Str::limit($subsystem->descripcion, 70) }}</small>
                                    @endif
                                </td>
                                <td><code>{{ $subsystem->slug }}</code></td>
                                <td><span class="status-badge {{ $subsystem->activo ? 'status-active' : 'status-inactive' }}">{{ $subsystem->activo ? 'Activo' : 'Inactivo' }}</span></td>
                                <td>{{ $subsystem->es_proveedor_identidad ? 'Sí' : 'No' }}</td>
                                <td>{{ $subsystem->accounts_count }}</td>
                                <td class="row-actions">
                                    <a class="text-button" href="{{ route('subsystems.show', $subsystem) }}">Ver</a>
                                    <a class="text-button" href="{{ route('subsystems.edit', $subsystem) }}">Editar</a>
                                    <form action="{{ route('subsystems.destroy', $subsystem) }}" method="POST" onsubmit="return confirm('¿Eliminar este subsistema?');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-button text-button-danger" type="submit">Eliminar</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
