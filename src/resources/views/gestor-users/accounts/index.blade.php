@extends('layouts.app')

@section('title', 'Cuentas del usuario')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Usuario gestionado</p>
                <h1 id="page-title">Cuentas de {{ $gestorUser->nombre_completo }}</h1>
                <p>Consulta y administra las cuentas vinculadas a cada subsistema.</p>
            </div>
            <a class="button button-primary" href="{{ route('gestor-users.accounts.create', $gestorUser) }}">Nueva cuenta</a>
        </div>

        @if ($gestorUser->subsystemAccounts->isEmpty())
            <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Este usuario aún no tiene cuentas activas.</div>
        @else
            <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                <table class="min-w-full border-collapse text-left">
                    <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                        <tr>
                            <th class="px-4 py-3.5 font-bold">Subsistema</th>
                            <th class="px-4 py-3.5 font-bold">Credencial</th>
                            <th class="px-4 py-3.5 font-bold">ID externo</th>
                            <th class="px-4 py-3.5 font-bold">Estado</th>
                            <th class="px-4 py-3.5 font-bold">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($gestorUser->subsystemAccounts as $account)
                            <tr class="border-t border-[#e8eee9]">
                                <td class="px-4 py-3.5 font-bold">{{ $account->subsystem?->nombre ?? 'No disponible' }}</td>
                                <td class="px-4 py-3.5">{{ $account->credencial_usuario }}</td>
                                <td class="px-4 py-3.5">{{ $account->external_account_id ?: 'No informado' }}</td>
                                <td class="px-4 py-3.5">{{ $account->estado?->value ?? $account->estado }}</td>
                                <td class="px-4 py-3.5">
                                    <div class="flex gap-3">
                                        <a class="font-semibold text-[#1f5d49]" href="{{ route('gestor-users.accounts.edit', [$gestorUser, $account]) }}">Editar</a>
                                        <form action="{{ route('gestor-users.accounts.destroy', [$gestorUser, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">
                                            @csrf
                                            @method('DELETE')
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

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('gestor-users.show', $gestorUser) }}">Volver al usuario</a>
        </div>
    </section>
@endsection
