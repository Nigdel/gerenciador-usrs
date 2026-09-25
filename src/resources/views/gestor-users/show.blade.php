@extends('layouts.app')

@section('title', $gestorUser->nombre_completo)

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Usuario gestionado</p>
                <h1 id="page-title">{{ $gestorUser->nombre_completo }}</h1>
                <p>Identidad central y cuentas externas vinculadas.</p>
            </div>
            <a class="button button-primary" href="{{ route('gestor-users.edit', $gestorUser) }}">Editar datos</a>
        </div>

        @if (session('provisioning_results'))
            <div class="mb-6 border border-[#d9e2dc] p-4">
                <h2 class="mb-3 font-bold text-[#17211b]">Resultado del aprovisionamiento</h2>
                @foreach (session('provisioning_results') as $result)
                    <p class="text-sm {{ $result['exito'] ? 'text-green-700' : 'text-red-700' }}">{{ $result['subsistema'] }}: {{ $result['mensaje'] }}</p>
                @endforeach
            </div>
        @endif

        <dl class="detail-grid">
            <div><dt>CPF</dt><dd>{{ $gestorUser->cpf }}</dd></div>
            <div><dt>Usuario</dt><dd>{{ $gestorUser->usuario }}</dd></div>
            <div><dt>Empresa</dt><dd>{{ $gestorUser->empresa }}</dd></div>
            <div><dt>E-mail personal</dt><dd>{{ $gestorUser->email_personal ?: 'No informado' }}</dd></div>
            <div><dt>Teléfono personal</dt><dd>{{ $gestorUser->telefono_personal ?: 'No informado' }}</dd></div>
            <div><dt>Teléfono de trabajo</dt><dd>{{ $gestorUser->telefono_trabajo ?: 'No informado' }}</dd></div>
        </dl>

        <div class="mt-8">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h2 class="text-xl font-bold text-[#17211b]">Cuentas en subsistemas</h2>
                <a class="button button-primary" href="{{ route('gestor-users.accounts.create', $gestorUser) }}">Nueva cuenta</a>
            </div>
            @if ($gestorUser->subsystemAccounts->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">Este usuario no tiene cuentas vinculadas.</div>
            @else
                <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                    <table class="min-w-full border-collapse text-left">
                        <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]"><tr><th class="px-4 py-3.5 font-bold">Subsistema</th><th class="px-4 py-3.5 font-bold">Credencial</th><th class="px-4 py-3.5 font-bold">ID externo</th><th class="px-4 py-3.5 font-bold">Estado</th><th class="px-4 py-3.5 font-bold">Acciones</th></tr></thead>
                        <tbody>
                            @foreach ($gestorUser->subsystemAccounts as $account)
                                <tr class="border-t border-[#e8eee9]"><td class="px-4 py-3.5 font-bold">{{ $account->subsystem?->nombre ?: 'No disponible' }}</td><td class="px-4 py-3.5">{{ $account->credencial_usuario }}</td><td class="px-4 py-3.5">{{ $account->external_account_id ?: 'No informado' }}</td><td class="px-4 py-3.5">{{ $account->estado?->value ?? $account->estado }}</td><td class="px-4 py-3.5"><div class="flex gap-2"><a class="text-sm font-semibold text-[#1f5d49]" href="{{ route('gestor-users.accounts.edit', [$gestorUser, $account]) }}">Editar</a><form action="{{ route('gestor-users.accounts.destroy', [$gestorUser, $account]) }}" method="POST" onsubmit="return confirm('¿Deseas eliminar esta cuenta?');">@csrf @method('DELETE')<button class="text-sm font-semibold text-red-700" type="submit">Eliminar</button></form></div></td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="form-actions"><a class="button button-secondary" href="{{ route('gestor-users.index') }}">Volver a usuarios</a></div>
    </section>
@endsection