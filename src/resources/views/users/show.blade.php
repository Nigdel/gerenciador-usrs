@extends('layouts.app')

@section('title', $user->name)

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="page-toolbar">
            <div class="form-heading">
                <p class="eyebrow">Gestão de acessos</p>
                <h1 id="page-title">{{ $user->name }}</h1>
                <p>Detalhes do usuário cadastrado.</p>
            </div>
            <a class="button button-primary" href="{{ route('users.edit', $user) }}">Editar usuário</a>
        </div>

        <dl class="detail-grid">
            <div><dt>E-mail</dt><dd>{{ $user->email ?: 'Não informado' }}</dd></div>
            <div><dt>CPF</dt><dd>{{ $user->cpf ?: 'Não informado' }}</dd></div>
            <div><dt>Telefone pessoal</dt><dd>{{ $user->telefone_pessoal ?: 'Não informado' }}</dd></div>
            <div><dt>Telefone de serviço</dt><dd>{{ $user->telefone_servico ?: 'Não informado' }}</dd></div>
            <div><dt>Empresa</dt><dd>{{ $user->empresa ?: 'Não informada' }}</dd></div>
            <div><dt>Cargo</dt><dd>{{ $user->cargo ?: 'Não informado' }}</dd></div>
            <div><dt>Tipo</dt><dd>{{ $user->externo ? 'Externo' : 'Interno' }}</dd></div>
            <div><dt>Encarregado</dt><dd>{{ $user->encarregado?->name ?: 'Não informado' }}</dd></div>
        </dl>

        <div class="mt-8">
            <h2 class="mb-4 text-xl font-bold text-[#17211b]">Cuentas en subsistemas</h2>
            @if ($user->subsystemAccounts->isEmpty())
                <div class="border border-dashed border-[#d9e2dc] px-5 py-6 text-[#68756d]">
                    Este usuario no tiene cuentas vinculadas a subsistemas.
                </div>
            @else
                <div class="overflow-x-auto rounded-lg border border-[#d9e2dc]">
                    <table class="min-w-full border-collapse text-left">
                        <thead class="bg-[#f7faf8] text-xs uppercase tracking-[0.04em] text-[#68756d]">
                            <tr>
                                <th class="px-4 py-3.5 font-bold">Subsistema</th>
                                <th class="px-4 py-3.5 font-bold">Usuario</th>
                                <th class="px-4 py-3.5 font-bold">ID externo</th>
                                <th class="px-4 py-3.5 font-bold">Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($user->subsystemAccounts as $account)
                                <tr class="border-t border-[#e8eee9] align-middle">
                                    <td class="px-4 py-3.5 font-bold text-[#17211b]">{{ $account->subsystem?->nombre ?: 'Subsistema no disponible' }}</td>
                                    <td class="px-4 py-3.5 text-[#17211b]">{{ $account->credencial_usuario }}</td>
                                    <td class="px-4 py-3.5 text-[#17211b]">{{ $account->external_account_id ?: 'No informado' }}</td>
                                    <td class="px-4 py-3.5"><span class="inline-flex rounded-full bg-emerald-50 px-2 py-1 text-xs font-bold text-emerald-800">{{ $account->estado?->value ?? $account->estado }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('users.index') }}">Voltar aos usuários</a>
        </div>
    </section>
@endsection