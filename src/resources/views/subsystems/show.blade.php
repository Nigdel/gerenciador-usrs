@extends('layouts.app')

@section('title', $subsystem->nombre)

@section('content')
@php
// Clases reutilizables basadas en la identidad visual de gestor-users/show
$btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
$btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
$btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
$btnDanger = $btnBase.' border border-red-200 bg-red-50 text-red-700 hover:bg-red-100 focus-visible:ring-red-500';
$btnIcon = 'px-3';
$th = 'px-4 py-3.5 font-bold';
@endphp

<section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
    {{-- Cabecera --}}
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">{{ $subsystem->nombre }}</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Detalle de la configuración y cuentas vinculadas al subsistema.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a class="{{ $btnPrimary }}" href="{{ route('subsystems.index') }}">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use href="#icon-arrow-left"></use>
                </svg>
                <span class="sr-only">Voltar</span>
            </a>            
            @if ($connectionTestable)
            @can('testConnection', $subsystem)
            <form action="{{ route('subsystems.test-connection', $subsystem) }}" method="POST">
                @csrf
                <button class="{{ $btnPrimary }} {{ $btnIcon }}" type="submit" aria-label="Probar disponibilidad de {{ $subsystem->nombre }}" title="Probar disponibilidad">
                    <svg class="h-4 w-4" aria-hidden="true">
                        <use href="#icon-sync"></use>
                    </svg>
                    <span class="sr-only">Probar disponibilidad</span>
                </button>
            </form>
            @endcan
            @endif
            @can('update', $subsystem)
            <a class="{{ $btnSecondary }} {{ $btnIcon }}" href="{{ route('subsystems.edit', $subsystem) }}" aria-label="Editar subsistema {{ $subsystem->nombre }}" title="Editar subsistema">
                <svg class="h-4 w-4" aria-hidden="true">
                    <use href="#icon-edit"></use>
                </svg>
                <span class="sr-only">Editar subsistema</span>
            </a>
            @endcan
        </div>
    </div>

    {{-- Detalles del Subsistema --}}
    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @php
        $details = [
        'Slug' => $subsystem->slug,
        'Estado' => $subsystem->activo ? 'Activo' : 'Inactivo',
        'Proveedor de identidad' => $subsystem->es_proveedor_identidad ? 'Sí' : 'No',
        'Cuentas asociadas' => $subsystem->accounts_count,
        'URL de API' => $subsystem->api_url ?: 'No configurada',
        'ID externo' => $subsystem->external_subsystem_id ?: 'No configurado',
        ];
        @endphp

        @foreach ($details as $etiqueta => $valor)
        <div class="rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4">
            <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">{{ $etiqueta }}</dt>
            <dd class="break-words text-[#17211b] {{ $valor === 'No configurada' || $valor === 'No configurado' ? 'text-[#a3b0a8]' : '' }}">
                @if($etiqueta === 'Slug')
                <code class="font-mono text-sm">{{ $valor }}</code>
                @else
                {{ $valor }}
                @endif
            </dd>
        </div>
        @endforeach

        @if ($connectionTestable)
        <div class="rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4">
            <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Última prueba de conexión</dt>
            <dd class="text-[#17211b]">
                {{ $subsystem->last_connection_test_at ? $subsystem->last_connection_test_at->format('d/m/Y H:i:s') : 'Nunca probado' }}
            </dd>
        </div>
        <div class="rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4">
            <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Resultado última prueba</dt>
            <dd>
                @if ($subsystem->last_connection_test_success === null)
                <span class="text-[#a3b0a8]">Sin pruebas realizadas</span>
                @elseif ($subsystem->last_connection_test_success)
                <span class="text-emerald-700 font-semibold">✓ Exitoso</span>
                @else
                <span class="text-red-700 font-semibold">✗ Fallido</span>
                @endif
            </dd>
        </div>
        @endif
    </dl>

    <div class="mt-6 rounded-xl border border-[#d9e2dc] bg-[#f7faf8] p-4">
        <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">Descripción</dt>
        <dd class="text-[#17211b]">{{ $subsystem->descripcion ?: 'Sin descripción' }}</dd>
    </div>

    {{-- Cuentas asociadas --}}
    <div class="mt-8">
        <div class="mb-4 flex items-center justify-between gap-3">
            <h2 class="text-xl font-bold text-[#17211b]">Cuentas asociadas</h2>
        </div>

        @if ($subsystem->accounts->isEmpty())
        <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">Este subsistema no tiene cuentas vinculadas.</div>
        @else
        <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
            <table class="min-w-full border-collapse text-left text-sm">
                <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                    <tr>
                        <th scope="col" class="{{ $th }}">Usuario</th>
                        <th scope="col" class="{{ $th }}">Credencial</th>
                        <th scope="col" class="{{ $th }}">ID externo</th>
                        <th scope="col" class="{{ $th }}">Estado</th>
                        <th scope="col" class="{{ $th }}"><span class="sr-only">Acciones</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#e8eee9]">
                    @foreach ($subsystem->accounts as $account)
                    @php($status = $account->estado?->value ?? $account->estado)
                    <tr class="align-middle transition hover:bg-[#f7faf8]">
                        <td class="px-4 py-3.5 font-bold text-[#17211b]">
                            {{ $account->user?->nombre_completo ?? 'No disponible' }}
                        </td>
                        <td class="px-4 py-3.5 font-mono text-[#17211b]">{{ $account->credencial_usuario }}</td>
                        <td class="px-4 py-3.5 font-mono {{ $account->external_account_id ? 'text-[#17211b]' : 'text-[#a3b0a8]' }}">
                            {{ $account->external_account_id ?: 'No informado' }}
                        </td>
                        <td class="px-4 py-3.5">@include('components.account-status', ['status' => $account->estado])</td>
                        <td class="px-4 py-3.5">
                            <div class="flex items-center justify-start gap-2 whitespace-nowrap sm:justify-end">
                                @if ($status === 'activo')
                                @can('disable', $account)
                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="operation" value="disable">
                                    <button class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 text-amber-700 transition hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" type="submit" aria-label="Deshabilitar cuenta {{ $account->credencial_usuario }}" title="Deshabilitar">
                                        <svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-ban"></use>
                                        </svg>
                                        <span class="sr-only">Deshabilitar</span>
                                    </button>
                                </form>
                                @endcan
                                @else
                                @can('reactivate', $account)
                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST">
                                    @csrf
                                    <input type="hidden" name="operation" value="enable">
                                    <button class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-emerald-200 bg-emerald-50 text-emerald-700 transition hover:bg-emerald-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" type="submit" aria-label="Habilitar cuenta {{ $account->credencial_usuario }}" title="Habilitar">
                                        <svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-check"></use>
                                        </svg>
                                        <span class="sr-only">Habilitar</span>
                                    </button>
                                </form>
                                @endcan
                                @endif
                                @can('delete', $account)
                                <form action="{{ route('subsystems.accounts.action', [$subsystem, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                    @csrf
                                    <input type="hidden" name="operation" value="delete">
                                    <button class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-red-200 bg-red-50 text-red-700 transition hover:bg-red-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" type="submit" aria-label="Eliminar cuenta {{ $account->credencial_usuario }}" title="Eliminar">
                                        <svg class="h-4 w-4" aria-hidden="true">
                                            <use href="#icon-trash"></use>
                                        </svg>
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
        @endif
    </div>


</section>
@endsection