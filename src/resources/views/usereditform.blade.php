@extends('layouts.app')

@section('title', 'Editar usuário')

@section('content')
    @php
        // Clases reutilizables basadas en la identidad visual de gestor-users/show
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $label = 'mb-1 block text-sm font-bold text-[#17211b]';
        $hint = 'mt-1 block text-xs text-[#68756d]';
        $err = 'mt-1 block text-xs font-bold text-red-700';
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8">
            <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700 hover:text-emerald-800" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Editar usuário</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Atualize os dados de {{ $user->name }}.</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                Revise os campos destacados.
            </div>
        @endif

        <form action="{{ route('users.update', $user) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="{{ $label }}" for="name">Nome completo <span aria-hidden="true" class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text" maxlength="255" class="{{ $input }}" value="{{ old('name', $user->name) }}" required>
                    @error('name')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="email">E-mail</label>
                    <input id="email" name="email" type="email" maxlength="255" class="{{ $input }}" value="{{ old('email', $user->email) }}">
                    @error('email')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="cpf">CPF</label>
                    <input id="cpf" name="cpf" type="text" maxlength="11" class="{{ $input }}" value="{{ old('cpf', $user->cpf) }}">
                    @error('cpf')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="telefone_pessoal">Telefone pessoal</label>
                    <input id="telefone_pessoal" name="telefone_pessoal" type="tel" maxlength="20" class="{{ $input }}" value="{{ old('telefone_pessoal', $user->telefone_pessoal) }}">
                    @error('telefone_pessoal')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="telefone_servico">Telefone de serviço</label>
                    <input id="telefone_servico" name="telefone_servico" type="tel" maxlength="20" class="{{ $input }}" value="{{ old('telefone_servico', $user->telefone_servico) }}">
                    @error('telefone_servico')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="empresa">Empresa</label>
                    <input id="empresa" name="empresa" type="text" maxlength="255" class="{{ $input }}" value="{{ old('empresa', $user->empresa) }}">
                    @error('empresa')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="cargo">Cargo</label>
                    <input id="cargo" name="cargo" type="text" maxlength="255" class="{{ $input }}" value="{{ old('cargo', $user->cargo) }}">
                    @error('cargo')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="encarregado_id">ID do encarregado</label>
                    <input id="encarregado_id" name="encarregado_id" type="number" min="1" class="{{ $input }}" value="{{ old('encarregado_id', $user->encarregado_id) }}">
                    @error('encarregado_id')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="flex flex-col">
                    <label class="{{ $label }}" for="role">Perfil de acesso</label>
                    <select id="role" name="role" class="{{ $input }}">
                        @foreach (\App\Models\User::ROLES as $roleOption)
                            <option value="{{ $roleOption }}" @selected(old('role', $user->role) === $roleOption)>
                                {{ \Illuminate\Support\Str::ucfirst($roleOption) }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="sm:col-span-2">
                    <label class="{{ $label }}" for="password">Nova senha</label>
                    <input id="password" name="password" type="password" minlength="8" class="{{ $input }}" autocomplete="new-password">
                    <small class="{{ $hint }}">Deixe em branco para manter a senha atual.</small>
                    @error('password')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="mt-6 flex items-center gap-3">
                <input id="externo" name="externo" type="checkbox" value="1" class="h-4 w-4 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-500" @checked(old('externo', $user->externo))>
                <label for="externo" class="text-sm font-bold text-[#17211b]">Este é um usuário externo</label>
            </div>

            <div class="mt-10 flex items-center gap-3 border-t border-[#d9e2dc] pt-6">
                <a class="{{ $btnSecondary }}" href="{{ route('users.index') }}">Cancelar</a>
                <button class="{{ $btnPrimary }}" type="submit">Salvar alterações</button>
            </div>
        </form>
    </section>
@endsection
