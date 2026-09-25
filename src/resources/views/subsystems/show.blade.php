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
                                    <td class="px-4 py-3.5">{{ $status }}</td>
                                    <td class="px-4 py-3.5">
                                        <div class="flex flex-wrap gap-3">
                                            @if ($status === 'activo')
                                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="operation" value="disable">
                                                    <button class="font-semibold text-amber-700" type="submit">Deshabilitar</button>
                                                </form>
                                            @else
                                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                                    @csrf
                                                    <input type="hidden" name="operation" value="enable">
                                                    <button class="font-semibold text-[#1f5d49]" type="submit">Habilitar</button>
                                                </form>
                                            @endif
                                            <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                                @csrf
                                                <input type="hidden" name="operation" value="delete">
                                                <button class="font-semibold text-red-700" type="submit">Eliminar</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

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
