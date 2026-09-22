@extends('layouts.app')

@section('title', 'Nuevo subsistema')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestão de acessos</p>
            <h1 id="page-title">Nuevo subsistema</h1>
            <p>Registra una plataforma para administrar sus integraciones y cuentas.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        <form action="{{ route('subsystems.store') }}" method="POST">
            @include('subsystems._form', ['subsystem' => new \App\Models\Subsystem()])
        </form>
    </section>
@endsection
