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

        <div class="form-actions">
            <a class="button button-secondary" href="{{ route('users.index') }}">Voltar aos usuários</a>
        </div>
    </section>
@endsection