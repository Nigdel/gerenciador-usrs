@extends('layouts.app')

@section('title', 'Gestão de usuários')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <a class="eyebrow" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title">Painel de usuários</h1>
            <p>Gerencie os acessos da sua equipe em um só lugar.</p>
        </div>

        <div class="form-actions">
            <!-- <a class="button button-primary" href="{{ route('users.index') }}">Usuários Locais</a> -->
            <a class="button button-primary" href="{{ route('gestor-users.index') }}">Usuarios Gestionados</a>
            <a class="button button-primary" href="{{ route('subsystems.index') }}">Listagem dos Subsystemas</a>
        </div>
    </section>
@endsection
