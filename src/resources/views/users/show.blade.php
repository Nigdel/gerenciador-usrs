@extends('layouts.app')

@section('title', $user->name)

@section('content')
    @php
        // Clases reutilizables
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $btnIcon = 'px-3';
        $th = 'px-4 py-3.5 font-bold';
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700 hover:text-emerald-800" href="{{ route('home') }}">Gestão de acessos</a>
                <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">{{ $user->name }}</h1>
                <p class="max-w-prose leading-6 text-[#68756d]">Detalhes do usuário cadastrado.</p>
            </div>
            <div class="flex items-center gap-2">
                @can('update', $user)
                    <a class="{{ $btnPrimary }} {{ $btnIcon }}" href="{{ route('users.edit', $user) }}" aria-label="Editar {{ $user->name }}" title="Editar usuário">
                        <svg class="h-4 w-4" aria-hidden="true"><use href="#icon-edit"></use></svg>
                        <span class="sr-only">Editar usuário</span>
                    </a>
                @endcan
            </div>
        </div>

        {{-- Datos del usuario --}}
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ([
                'E-mail' => $user->email,
                'CPF' => $user->cpf,
                'Telefone pessoal' => $user->telefone_pessoal,
                'Telefone de serviço' => $user->telefone_servico,
                'Empresa' => $user->empresa,
                'Cargo' => $user->cargo,
                'Tipo' => $user->externo ? 'Externo' : 'Interno',
                'Encarregado' => $user->encarregado?->name,
            ] as $etiqueta => $valor)
                <div class="rounded-lg border border-[#d9e2dc] bg-[#f7faf8] p-4">
                    <dt class="mb-1 text-xs font-bold uppercase tracking-[0.06em] text-[#68756d]">{{ $etiqueta }}</dt>
                    <dd class="break-words text-[#17211b] {{ !$valor ? 'text-[#a3b0a8]' : '' }}">{{ $valor ?: 'Não informado' }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Cuentas en subsistemas --}}
        <div class="mt-8 border-t border-[#d9e2dc] pt-8">
            <h2 class="mb-4 text-xl font-bold text-[#17211b]">Cuentas en subsistemas</h2>
            @if ($user->subsystemAccounts->isEmpty())
                <div class="rounded-lg border border-dashed border-[#d9e2dc] bg-[#f7faf8] px-5 py-8 text-center text-sm text-[#68756d]">Este usuario no tiene cuentas vinculadas a subsistemas.</div>
            @else
                <div class="overflow-x-auto rounded-xl border border-[#d9e2dc]">
                    <table class="min-w-full border-collapse text-left text-sm">
                        <thead class="border-b border-[#d9e2dc] bg-[#f7faf8] text-xs uppercase tracking-[0.06em] text-[#68756d]">
                            <tr>
                                <th class="{{ $th }}">Subsistema</th>
                                <th class="{{ $th }}">Usuario</th>
                                <th class="{{ $th }}">ID externo</th>
                                <th class="{{ $th }}">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[#e8eee9]">
                            @foreach ($user->subsystemAccounts as $account)
                                <tr class="align-middle transition hover:bg-[#f7faf8]">
                                    <td class="px-4 py-3.5 font-bold text-[#17211b]">{{ $account->subsystem?->nombre ?: 'Subsistema no disponible' }}</td>
                                    <td class="px-4 py-3.5 font-mono text-[#17211b]">{{ $account->credencial_usuario }}</td>
                                    <td class="px-4 py-3.5 font-mono {{ !$account->external_account_id ? 'text-[#a3b0a8]' : 'text-[#17211b]' }}">{{ $account->external_account_id ?: 'No informado' }}</td>
                                    <td class="px-4 py-3.5">@include('components.account-status', ['status' => $account->estado])</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div class="mt-8 flex gap-2 border-t border-[#d9e2dc] pt-6">
            <a class="{{ $btnSecondary }}" href="{{ route('users.index') }}">Voltar aos usuários</a>
        </div>
    </section>
@endsection
