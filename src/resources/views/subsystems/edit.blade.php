@extends('layouts.app')

@section('title', 'Editar subsistema')

@section('content')
    <section class="form-panel crud-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestão de acessos</p>
            <h1 id="page-title">Editar subsistema</h1>
            <p>Actualiza los datos y el estado de {{ $subsystem->nombre }}.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        <form action="{{ route('subsystems.update', $subsystem) }}" method="POST">
            @include('subsystems._form')
        </form>
    </section>
@endsection
