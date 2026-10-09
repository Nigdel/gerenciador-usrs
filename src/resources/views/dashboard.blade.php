@extends('layouts.app')

@section('title', 'Painel de Controle')

@section('content')
    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8">
            <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Painel de Métricas</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Visão geral do estado do sistema e provisionamento.</p>
        </div>

        <div class="grid gap-6 md:grid-cols-3">
            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-[#17211b]">Usuários</strong>
                <span class="text-2xl font-bold text-emerald-700">{{ $metrics['users']['total'] ?? 0 }}</span>
            </div>

            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-[#17211b]">Contas</strong>
                <span class="text-2xl font-bold text-emerald-700">{{ $metrics['accounts']['total'] ?? 0 }}</span>
            </div>

            <div class="rounded-lg border border-[#d9e2dc] p-5 bg-[#f7faf8]">
                <strong class="block mb-2 text-[#17211b]">Operações Falhas</strong>
                <span class="text-2xl font-bold text-red-600">{{ $metrics['operations']['failed'] ?? 0 }}</span>
            </div>
        </div>
    </section>
@endsection