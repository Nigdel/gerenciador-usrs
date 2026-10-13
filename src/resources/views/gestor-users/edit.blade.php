@extends('layouts.app')

@section('title', 'Editar usuario gestionado')

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
            <h1 id="page-title" class="mb-2 text-3xl font-bold tracking-normal text-[#17211b]">Editar usuario gestionado</h1>
            <p class="max-w-prose leading-6 text-[#68756d]">Actualiza los datos centrales de {{ $gestorUser->nombre_completo }}. Las cuentas existentes no se modifican desde este formulario: guarda los cambios y luego usa «Sincronizar datos con subsistemas» en la ficha si quieres propagarlos.</p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-bold text-red-700" role="alert">
                Revisa los campos destacados.
            </div>
        @endif

        <form action="{{ route('gestor-users.update', $gestorUser) }}" method="POST">
            @csrf
            @method('PUT')

            <div class="grid gap-6 sm:grid-cols-2">
                @foreach ([
                    'nombre_completo' => ['Nombre completo', 'text'],
                    'cpf' => ['CPF', 'text'],
                    'usuario' => ['Usuario', 'text'],
                    'email_personal' => ['E-mail personal', 'email'],
                    'empresa' => ['Empresa', 'text'],
                    'telefono_personal' => ['Teléfono personal', 'tel'],
                    'telefono_trabajo' => ['Teléfono de trabajo', 'tel'],
                    'direccion_particular' => ['Dirección particular', 'text'],
                ] as $field => [$labelName, $type])
                    <div class="{{ $field === 'nombre_completo' || $field === 'direccion_particular' ? 'sm:col-span-2' : '' }}">
                        <label class="{{ $label }}" for="{{ $field }}">{{ $labelName }} @if (in_array($field, ['nombre_completo', 'cpf', 'empresa'], true))<span aria-hidden="true" class="text-red-500">*</span>@endif</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="255" class="{{ $input }}" value="{{ old($field, $gestorUser->{$field}) }}" @required(in_array($field, ['nombre_completo', 'cpf', 'empresa'], true))>
                        @error($field)<small class="{{ $err }}">{{ $message }}</small>@enderror
                    </div>
                @endforeach

                <div class="sm:col-span-2">
                    <label class="{{ $label }}" for="password_general">Nueva contraseña</label>
                    <input id="password_general" name="password_general" type="password" minlength="8" class="{{ $input }}" autocomplete="new-password">
                    <small class="{{ $hint }}">Deja este campo vacío para conservar la contraseña actual.</small>
                    @error('password_general')<small class="{{ $err }}">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="mt-10 flex items-center gap-3 border-t border-[#d9e2dc] pt-6">
                <a class="{{ $btnSecondary }}" href="{{ route('gestor-users.show', $gestorUser) }}">Cancelar</a>
                <button class="{{ $btnPrimary }}" type="submit">Guardar cambios</button>
            </div>
        </form>
    </section>
@endsection
