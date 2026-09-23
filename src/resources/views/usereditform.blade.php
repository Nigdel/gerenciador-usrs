@extends('layouts.app')

@section('title', 'Editar usuário')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestão de acessos</p>
            <h1 id="page-title">Editar usuário</h1>
            <p>Atualize os dados de {{ $user->name }}.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revise os campos destacados.</div>
        @endif

        <form action="{{ route('users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="field-grid">
                <div class="field field-wide">
                    <label for="name">Nome completo <span aria-hidden="true">*</span></label>
                    <input id="name" name="name" type="text" maxlength="255" value="{{ old('name', $user->name) }}" required>
                    @error('name')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="email">E-mail</label>
                    <input id="email" name="email" type="email" maxlength="255" value="{{ old('email', $user->email) }}">
                    @error('email')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="cpf">CPF</label>
                    <input id="cpf" name="cpf" type="text" maxlength="11" value="{{ old('cpf', $user->cpf) }}">
                    @error('cpf')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="telefone_pessoal">Telefone pessoal</label>
                    <input id="telefone_pessoal" name="telefone_pessoal" type="tel" maxlength="20" value="{{ old('telefone_pessoal', $user->telefone_pessoal) }}">
                    @error('telefone_pessoal')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="telefone_servico">Telefone de serviço</label>
                    <input id="telefone_servico" name="telefone_servico" type="tel" maxlength="20" value="{{ old('telefone_servico', $user->telefone_servico) }}">
                    @error('telefone_servico')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="empresa">Empresa</label>
                    <input id="empresa" name="empresa" type="text" maxlength="255" value="{{ old('empresa', $user->empresa) }}">
                    @error('empresa')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="cargo">Cargo</label>
                    <input id="cargo" name="cargo" type="text" maxlength="255" value="{{ old('cargo', $user->cargo) }}">
                    @error('cargo')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="encarregado_id">ID do encarregado</label>
                    <input id="encarregado_id" name="encarregado_id" type="number" min="1" value="{{ old('encarregado_id', $user->encarregado_id) }}">
                    @error('encarregado_id')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field field-wide">
                    <label for="password">Nova senha</label>
                    <input id="password" name="password" type="password" minlength="8" autocomplete="new-password">
                    <small class="field-hint">Deixe em branco para manter a senha atual.</small>
                    @error('password')<small class="error-message">{{ $message }}</small>@enderror
                </div>
            </div>

            <label class="checkbox-field" for="externo">
                <input id="externo" name="externo" type="checkbox" value="1" @checked(old('externo', $user->externo))>
                <span>Este é um usuário externo</span>
            </label>

            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('users.index') }}">Cancelar</a>
                <button class="button button-primary" type="submit">Salvar alterações</button>
            </div>
        </form>
    </section>
@endsection