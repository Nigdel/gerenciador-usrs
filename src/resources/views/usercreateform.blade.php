@extends('layouts.app')

@section('title', 'Cadastrar usuário')

@section('content')
    @php
        // Clases reutilizables basadas en la identidad visual de gestor-users/edit
        $btnBase = 'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2';
        $btnPrimary = $btnBase.' bg-emerald-700 text-white hover:bg-emerald-800';
        $btnSecondary = $btnBase.' border border-[#d9e2dc] bg-white text-[#17211b] hover:bg-[#f7faf8]';
        $input = 'w-full rounded-lg border border-[#d9e2dc] bg-white px-3.5 py-2.5 text-sm text-[#17211b] placeholder:text-[#a3b0a8] transition focus:border-emerald-600 focus:outline-none focus:ring-2 focus:ring-emerald-600/20';
        $label = 'mb-1 block text-sm font-bold text-[#17211b]';
        $err = 'mt-1 block text-xs font-bold text-red-700';
    @endphp

    <section class="w-full max-w-6xl rounded-xl border border-emerald-900/10 bg-white p-6 shadow-[0_24px_60px_rgba(23,52,37,0.12)] sm:p-10" aria-labelledby="page-title">
        {{-- Cabecera --}}
        <div class="mb-8">
            <a class="mb-2 block text-xs font-bold uppercase tracking-[0.08em] text-emerald-700 hover:text-emerald-800" href="{{ route('home') }}">Gestão de acessos</a>
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Cadastrar novo usuário</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Crie uma nova conta de acesso ao sistema.</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                Revisa los campos destacados.
            </div>
        @endif

        <form action="{{ route('users.store') }}" method="POST">
            @csrf

            <div class="grid gap-6 sm:grid-cols-2">
                <div class="">
                    <label class="{{ $label }}" for="name">Nome <span aria-hidden="true" class="text-red-500">*</span></label>
                    <input type="text" name="name" id="name" class="{{ $input }}" value="{{ old('name') }}" required>
                    @error('name')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="">
                    <label class="{{ $label }}" for="email">Email <span aria-hidden="true" class="text-red-500">*</span></label>
                    <input type="email" name="email" id="email" class="{{ $input }}" value="{{ old('email') }}" required>
                    @error('email')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="">
                    <label class="{{ $label }}" for="password">Senha <span aria-hidden="true" class="text-red-500">*</span></label>
                    <input type="password" name="password" id="password" class="{{ $input }}" required>
                    @error('password')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>

                <div class="">
                    <label class="{{ $label }}" for="password_confirmation">Confirmar Senha <span aria-hidden="true" class="text-red-500">*</span></label>
                    <input type="password" name="password_confirmation" id="password_confirmation" class="{{ $input }}" required>
                </div>

                <div class="">
                    <label class="{{ $label }}" for="role">Perfil de acesso</label>
                    <select name="role" id="role" class="{{ $input }}">
                        @foreach (\App\Models\User::ROLES as $roleOption)
                            <option value="{{ $roleOption }}" @selected(old('role', 'operador') === $roleOption)>
                                {{ \Illuminate\Support\Str::ucfirst($roleOption) }}
                            </option>
                        @endforeach
                    </select>
                    @error('role')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="mt-10 flex items-center gap-3 border-t border-[#d9e2dc] pt-6">
                <a class="{{ $btnSecondary }}" href="{{ route('users.index') }}">Cancelar</a>
                <button class="{{ $btnPrimary }}" type="submit">Cadastrar usuário</button>
            </div>
        </form>
    </section>
@endsection
