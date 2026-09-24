@extends('layouts.app')

@section('title', 'Nuevo usuario gestionado')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestión de accesos externos</p>
            <h1 id="page-title">Nuevo usuario gestionado</h1>
            <p>Registra la persona y crea sus cuentas en los subsistemas seleccionados.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        <form action="{{ route('gestor-users.store') }}" method="POST">
            @csrf
            <div class="field-grid">
                <div class="field field-wide">
                    <label for="nombre_completo">Nombre completo <span aria-hidden="true">*</span></label>
                    <input id="nombre_completo" name="nombre_completo" type="text" maxlength="255" value="{{ old('nombre_completo') }}" required>
                    @error('nombre_completo')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="cpf">CPF <span aria-hidden="true">*</span></label>
                    <input id="cpf" name="cpf" type="text" maxlength="20" value="{{ old('cpf') }}" required>
                    @error('cpf')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="usuario">Usuario</label>
                    <input id="usuario" name="usuario" type="text" maxlength="255" value="{{ old('usuario') }}">
                    <small class="field-hint">Si queda vacío, el sistema propondrá uno.</small>
                    @error('usuario')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="email_personal">E-mail personal</label>
                    <input id="email_personal" name="email_personal" type="email" maxlength="255" value="{{ old('email_personal') }}">
                    @error('email_personal')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="empresa">Empresa <span aria-hidden="true">*</span></label>
                    <input id="empresa" name="empresa" type="text" maxlength="255" value="{{ old('empresa') }}" required>
                    @error('empresa')<small class="error-message">{{ $message }}</small>@enderror
                </div>
                <div class="field">
                    <label for="telefono_personal">Teléfono personal</label>
                    <input id="telefono_personal" name="telefono_personal" type="tel" maxlength="30" value="{{ old('telefono_personal') }}">
                </div>
                <div class="field">
                    <label for="telefono_trabajo">Teléfono de trabajo</label>
                    <input id="telefono_trabajo" name="telefono_trabajo" type="tel" maxlength="30" value="{{ old('telefono_trabajo') }}">
                </div>
                <div class="field field-wide">
                    <label for="direccion_particular">Dirección particular</label>
                    <input id="direccion_particular" name="direccion_particular" type="text" maxlength="255" value="{{ old('direccion_particular') }}">
                </div>
                <div class="field field-wide">
                    <label for="password_general">Contraseña general <span aria-hidden="true">*</span></label>
                    <input id="password_general" name="password_general" type="password" minlength="8" autocomplete="new-password" required>
                    <small class="field-hint">Se almacenará con hash y se utilizará para el aprovisionamiento.</small>
                    @error('password_general')<small class="error-message">{{ $message }}</small>@enderror
                </div>
            </div>

            <fieldset class="mt-8 border-t border-[#d9e2dc] pt-6">
                <legend class="mb-2 text-lg font-bold text-[#17211b]">Cuentas a crear</legend>
                <p class="mb-4 text-sm text-[#68756d]">Selecciona uno o más subsistemas activos.</p>
                @if ($subsystems->isEmpty())
                    <p class="feedback feedback-error">No hay subsistemas activos disponibles.</p>
                @else
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($subsystems as $subsystem)
                            <label class="checkbox-field rounded-md border border-[#d9e2dc] p-3" for="subsystem-{{ $subsystem->id }}">
                                <input id="subsystem-{{ $subsystem->id }}" name="subsistemas[]" type="checkbox" value="{{ $subsystem->slug }}" @checked(in_array($subsystem->slug, old('subsistemas', []), true))>
                                <span><strong>{{ $subsystem->nombre }}</strong><small class="block text-[#68756d]">{{ $subsystem->descripcion ?: $subsystem->slug }}</small></span>
                            </label>
                        @endforeach
                    </div>
                @endif
                @error('subsistemas')<small class="error-message">{{ $message }}</small>@enderror
                @error('subsistemas.*')<small class="error-message">{{ $message }}</small>@enderror
            </fieldset>

            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('gestor-users.index') }}">Cancelar</a>
                <button class="button button-primary" type="submit" @disabled($subsystems->isEmpty())>Crear usuario y cuentas</button>
            </div>
        </form>
    </section>
@endsection