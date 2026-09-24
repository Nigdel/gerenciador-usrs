@extends('layouts.app')

@section('title', 'Gestão de usuários')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestão de acessos</p>
            <h1 id="page-title">Painel de usuários</h1>
            <p>Gerencie os acessos da sua equipe em um só lugar.</p>
        </div>

        <div class="form-actions">
            <a class="button button-primary" href="{{ url('/users/create') }}">Cadastrar usuário</a>
            <a class="button button-secondary" href="{{ route('gestor-users.index') }}">Usuarios gestionados</a>
        </div>
    </section>
@endsection
