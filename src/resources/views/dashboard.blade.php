@extends('layouts.app')

@section('title', 'Painel')

@section('content')
    <section class="w-full max-w-5xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        <div class="mb-8">
            <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Painel</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Bem-vindo, {{ auth()->user()->name }}.</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            @can('viewAny', [App\Models\Subsystem::class])
                <a class="block rounded-lg border border-[#d9e2dc] p-5 no-underline transition hover:border-emerald-700 hover:bg-[#f7faf8]" href="{{ route('subsystems.index') }}">
                    <strong class="block text-[#17211b]">Subsistemas</strong>
                    <span class="text-sm text-[#68756d]">Administra las plataformas conectadas.</span>
                </a>
            @endcan

            @can('viewAny', [App\Models\GestorUser::class])
                <a class="block rounded-lg border border-[#d9e2dc] p-5 no-underline transition hover:border-emerald-700 hover:bg-[#f7faf8]" href="{{ route('gestor-users.index') }}">
                    <strong class="block text-[#17211b]">Usuários</strong>
                    <span class="text-sm text-[#68756d]">Altas, suspensões y estados por subsistema.</span>
                </a>
            @endcan

            @can('viewAny', [App\Models\User::class])
                <a class="block rounded-lg border border-[#d9e2dc] p-5 no-underline transition hover:border-emerald-700 hover:bg-[#f7faf8]" href="{{ route('users.index') }}">
                    <strong class="block text-[#17211b]">Operadores</strong>
                    <span class="text-sm text-[#68756d]">Quién accede al sistema y con qué rol.</span>
                </a>
            @endcan
        </div>
    </section>
@endsection
