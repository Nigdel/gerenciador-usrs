@extends('layouts.app')

@section('title', $subsystem->nombre)

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Gestão de acessos</p>
                <h1 id="page-title">{{ $subsystem->nombre }}</h1>
                <p>Detalle de la configuración del subsistema.</p>
            </div>
            <a class="button button-primary" href="{{ route('subsystems.edit', $subsystem) }}">Editar subsistema</a>
        </div>

        <dl class="detail-grid">
            <div><dt>Slug</dt><dd><code>{{ $subsystem->slug }}</code></dd></div>
            <div><dt>Estado</dt><dd>{{ $subsystem->activo ? 'Activo' : 'Inactivo' }}</dd></div>
            <div><dt>Proveedor de identidad</dt><dd>{{ $subsystem->es_proveedor_identidad ? 'Sí' : 'No' }}</dd></div>
            <div><dt>Cuentas asociadas</dt><dd>{{ $subsystem->accounts_count }}</dd></div>
            <div><dt>URL de API</dt><dd>{{ $subsystem->api_url ?: 'No configurada' }}</dd></div>
            <div><dt>ID externo</dt><dd>{{ $subsystem->external_subsystem_id ?: 'No configurado' }}</dd></div>
            <div class="detail-wide"><dt>Descripción</dt><dd>{{ $subsystem->descripcion ?: 'Sin descripción' }}</dd></div>
        </dl>

        @if ($connectionTestable)
            <div class="mt-8 border-t border-[#d9e2dc] pt-6">
                <h2 class="mb-2 text-xl font-bold text-[#17211b]">Disponibilidad del subsistema</h2>
                <p class="mb-4 text-sm text-[#68756d]">Realiza una petición autenticada para verificar que la API está disponible.</p>
                <form action="{{ route('subsystems.test-connection', $subsystem) }}" method="POST">
                    @csrf
                    <button class="button button-primary" type="submit">Probar disponibilidad</button>
                </form>
            </div>
        @endif

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('subsystems.index') }}">Volver a subsistemas</a>
        </div>
    </section>
@endsection
