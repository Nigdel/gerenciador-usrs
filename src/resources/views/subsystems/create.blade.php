@extends('layouts.app')

@section('title', 'Nuevo subsistema')

@section('content')
    @php
        // Clases reutilizables basadas en la identidad visual de gestor-users/edit
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8">
            <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700 hover:text-emerald-800" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Nuevo subsistema</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Registra una plataforma para administrar sus integraciones y cuentas.</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                Revisa los campos destacados.
            </div>
        @endif

        <form action="{{ route('subsystems.store') }}" method="POST">
            @include('subsystems._form', ['subsystem' => new \App\Models\Subsystem()])
        </form>
    </section>
@endsection
