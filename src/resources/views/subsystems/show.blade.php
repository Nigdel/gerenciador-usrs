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
            <div class="flex gap-3">
                @if ($connectionTestable)
                    @can('testConnection', $subsystem)
                        <form action="{{ route('subsystems.test-connection', $subsystem) }}" method="POST">
                            @csrf
                            <button class="button button-secondary" type="submit">Probar disponibilidad</button>
                        </form>
                    @endcan
                @endif
                @can('update', $subsystem)
                    <a class="button button-primary" href="{{ route('subsystems.edit', $subsystem) }}">Editar subsistema</a>
                @endcan
            </div>
        </div>

        <dl class="detail-grid">
            <div><dt>Slug</dt><dd><code>{{ $subsystem->slug }}</code></dd></div>
            <div><dt>Estado</dt><dd>{{ $subsystem->activo ? 'Activo' : 'Inactivo' }}</dd></div>
            <div><dt>Proveedor de identidad</dt><dd>{{ $subsystem->es_proveedor_identidad ? 'Sí' : 'No' }}</dd></div>
            <div><dt>Cuentas asociadas</dt><dd>{{ $subsystem->accounts_count }}</dd></div>
            <div><dt>URL de API</dt><dd>{{ $subsystem->api_url ?: 'No configurada' }}</dd></div>
            <div><dt>ID externo</dt><dd>{{ $subsystem->external_subsystem_id ?: 'No configurado' }}</dd></div>
            @if ($connectionTestable)
                <div><dt>Última prueba de conexión</dt><dd>{{ $subsystem->last_connection_test_at ? $subsystem->last_connection_test_at->format('d/m/Y H:i:s') : 'Nunca probado' }}</dd></div>
                <div><dt>Resultado última prueba</dt><dd>
                    @if ($subsystem->last_connection_test_success === null)
                        <span class="text-[#68756d]">Sin pruebas realizadas</span>
                    @elseif ($subsystem->last_connection_test_success)
                        <span class="text-[#1f5d49] font-semibold">✓ Exitoso</span>
                    @else
                        <span class="text-red-700 font-semibold">✗ Fallido</span>
                    @endif
                </dd></div>
            @endif
            <div class="detail-wide"><dt>Descripción</dt><dd>{{ $subsystem->descripcion ?: 'Sin descripción' }}</dd></div>
        </dl>

        <div class="mt-8 border-t border-[#d9e2dc] pt-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-[#17211b]">Cuentas asociadas</h2>
            </div>
            @if ($subsystem->accounts->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Este subsistema no tiene cuentas vinculadas.</div>
            @else
                <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                    <table class="min-w-full border-collapse text-left">
                        <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                            <tr>
                                <th class="px-4 py-3.5 font-bold">Usuario</th>
                                <th class="px-4 py-3.5 font-bold">Credencial</th>
                                <th class="px-4 py-3.5 font-bold">ID externo</th>
                                <th class="px-4 py-3.5 font-bold">Estado</th>
                                <th class="px-4 py-3.5 font-bold">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($subsystem->accounts as $account)
                                @php($status = $account->estado?->value ?? $account->estado)
                                <tr class="border-t border-[#e8eee9]">
                                    <td class="px-4 py-3.5 font-bold">{{ $account->user?->nombre_completo ?? 'No disponible' }}</td>
                                    <td class="px-4 py-3.5">{{ $account->credencial_usuario }}</td>
                                    <td class="px-4 py-3.5">{{ $account->external_account_id ?: 'No informado' }}</td>
                                    <td class="px-4 py-3.5">@include('components.account-status', ['status' => $status])</td>
                                    <td class="px-4 py-3.5">
                                        <div class="flex flex-wrap gap-3">
                                            @if ($status === 'activo')
                                                @can('disable', $account)
                                                    <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                                        @csrf
                                                        <input type="hidden" name="operation" value="disable">
                                                        <button class="inline-flex items-center justify-center text-amber-700" type="submit" aria-label="Deshabilitar cuenta {{ $account->credencial_usuario }}" title="Deshabilitar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-ban"></use></svg><span class="sr-only">Deshabilitar</span></button>
                                                    </form>
                                                @endcan
                                            @else
                                                @can('reactivate', $account)
                                                    <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                                        @csrf
                                                        <input type="hidden" name="operation" value="enable">
                                                        <button class="inline-flex items-center justify-center text-[#1f5d49]" type="submit" aria-label="Habilitar cuenta {{ $account->credencial_usuario }}" title="Habilitar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-check"></use></svg><span class="sr-only">Habilitar</span></button>
                                                    </form>
                                                @endcan
                                            @endif
                                            @can('delete', $account)
                                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                                    @csrf
                                                    <input type="hidden" name="operation" value="delete">
                                                    <button class="inline-flex items-center justify-center text-red-700" type="submit" aria-label="Eliminar cuenta {{ $account->credencial_usuario }}" title="Eliminar"><svg class="h-5 w-5" aria-hidden="true"><use href="#icon-trash"></use></svg><span class="sr-only">Eliminar</span></button>
                                                </form>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('subsystems.index') }}">Volver a subsistemas</a>
        </div>
    </section>
@endsection
