@extends('layouts.app')

@section('title', 'Editar usuario gestionado')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestión de accesos externos</p>
            <h1 id="page-title">Editar usuario gestionado</h1>
            <p>Actualiza los datos centrales de {{ $gestorUser->nombre_completo }}. Las cuentas existentes no se modifican desde este formulario.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        <form action="{{ route('gestor-users.update', $gestorUser) }}" method="POST">
            @csrf
            @method('PUT')
            <div class="field-grid">
                @foreach ([
                    'nombre_completo' => ['Nombre completo', 'text'],
                    'cpf' => ['CPF', 'text'],
                    'usuario' => ['Usuario', 'text'],
                    'email_personal' => ['E-mail personal', 'email'],
                    'empresa' => ['Empresa', 'text'],
                    'telefono_personal' => ['Teléfono personal', 'tel'],
                    'telefono_trabajo' => ['Teléfono de trabajo', 'tel'],
                    'direccion_particular' => ['Dirección particular', 'text'],
                ] as $field => [$label, $type])
                    <div class="field {{ $field === 'nombre_completo' || $field === 'direccion_particular' ? 'field-wide' : '' }}">
                        <label for="{{ $field }}">{{ $label }} @if (in_array($field, ['nombre_completo', 'cpf', 'empresa'], true))<span aria-hidden="true">*</span>@endif</label>
                        <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" maxlength="255" value="{{ old($field, $gestorUser->{$field}) }}" @required(in_array($field, ['nombre_completo', 'cpf', 'empresa'], true))>
                        @error($field)<small class="error-message">{{ $message }}</small>@enderror
                    </div>
                @endforeach
                <div class="field field-wide">
                    <label for="password_general">Nueva contraseña</label>
                    <input id="password_general" name="password_general" type="password" minlength="8" autocomplete="new-password">
                    <small class="field-hint">Deja este campo vacío para conservar la contraseña actual.</small>
                    @error('password_general')<small class="error-message">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('gestor-users.show', $gestorUser) }}">Cancelar</a>
                <button class="button button-primary" type="submit">Guardar cambios</button>
            </div>
        </form>
    </section>
@endsection