@extends('layouts.app')

@section('title', 'Gestão de usuários')

@section('content')
    @php
        // Identidade visual extraída de usereditform.blade.php
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $card = 'rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10';
    @endphp

    <div class="py-12 sm:py-20">
        {{-- Hero Section --}}
        <section class="mx-auto max-w-6xl px-6 text-center">
            <div class="mb-8">
                <a class="mb-4 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700 hover:text-emerald-800" href="#">
                    Gestão de acessos
                </a>
                <h1 class="mb-6 text-4xl font-bold tracking-tight text-[#17211b] sm:text-6xl">
                    Painel de usuários <span class="text-emerald-700">Inteligente</span>
                </h1>
                <p class="mx-auto mb-10 max-w-2xl leading-8 text-[#68756d] text-lg">
                    Gerencie os acessos da sua equipe em um só lugar. Simplifique a provisão de contas, controle permissões e mantenha a segurança da sua infraestrutura de forma centralizada.
                </p>
                <div class="flex items-center justify-center gap-4">
                    <a class="{{ $btnPrimary }} px-8 py-3 text-base" href="{{ route('gestor-users.index') }}">
                        Começar agora
                    </a>
                    <a class="{{ $btnSecondary }} px-8 py-3 text-base" href="#features">
                        Saiba mais
                    </a>
                </div>
            </div>
        </section>

        {{-- Features Section --}}
        <section id="features" class="mx-auto max-w-6xl px-6 pt-20 pb-12">
            <div class="mb-16 text-center">
                <h2 class="text-3xl font-bold text-[#17211b]">Tudo o que você precisa</h2>
                <p class="mt-4 text-[#68756d]">Ferramentas robustas para a gestão de identidades e acessos.</p>
            </div>

            <div class="grid gap-8 sm:grid-cols-3">
                {{-- Feature 1 --}}
                <div class="{{ $card }} flex flex-col items-start text-left">
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.058M15 19.128v-.003m0 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM15 19.128v-.003m0 0c3.313 0 6-2.687 6-6S18.313 7 15 7m-3-13H5a2 2 0 0 0-2 2v18a2 2 0 0 0 2 2h18a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-xl font-bold text-[#17211b]">Gestão Centralizada</h3>
                    <p class="leading-6 text-[#68756d]">
                        Visualize e edite todos os usuários e suas permissões em um único painel administrativo intuitivo.
                    </p>
                </div>

                {{-- Feature 2 --}}
                <div class="{{ $card }} flex flex-col items-start text-left">
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.95 11.95 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.643a11.953 11.953 0 0 0 9-11.643 11.99 11.99 0 0 0-3.598-3.749A11.95 11.95 0 0 1 12 2.714z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-xl font-bold text-[#17211b]">Segurança Avançada</h3>
                    <p class="leading-6 text-[#68756d]">
                        Controle rigoroso de perfis de acesso e auditoria de alterações para garantir a conformidade.
                    </p>
                </div>

                {{-- Feature 3 --}}
                <div class="{{ $card }} flex flex-col items-start text-left">
                    <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-6 w-6">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                    </div>
                    <h3 class="mb-2 text-xl font-bold text-[#17211b]">Sincronização Rápida</h3>
                    <p class="leading-6 text-[#68756d]">
                        Integração com subsistemas para provisão automática de contas e atualização de dados.
                    </p>
                </div>
            </div>
        </section>

        {{-- Final CTA --}}
        <section class="mx-auto max-w-4xl px-6 py-20">
            <div class="{{ $card }} bg-emerald-900 text-white text-center">
                <h2 class="mb-4 text-3xl font-bold">Pronto para organizar seus acessos?</h2>
                <p class="mb-8 text-emerald-100">Junte-se a nós e transforme a gestão de usuários da sua empresa.</p>
                <a class="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-6 py-3 text-sm font-bold text-emerald-900 transition hover:bg-emerald-50 focus:outline-none focus:ring-2 focus:ring-emerald-500" href="{{ route('gestor-users.index') }}">
                    Acessar o Painel
                </a>
            </div>
        </section>
    </div>
@endsection
