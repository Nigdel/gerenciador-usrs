@extends('layouts.app')

@section('title', 'Nueva cuenta')

@section('content')
    <section class="form-panel" aria-labelledby="page-title">
        <div class="form-heading">
            <p class="eyebrow">Gestión de accesos externos</p>
            <h1 id="page-title">Nueva cuenta para {{ $gestorUser->nombre_completo }}</h1>
            <p>Configura la cuenta del usuario en un subsistema disponible.</p>
        </div>

        @if ($errors->any())
            <div class="feedback feedback-error" role="alert">Revisa los campos destacados.</div>
        @endif

        <form action="{{ route('gestor-users.accounts.store', $gestorUser) }}" method="POST">
            @csrf
            <div class="field-grid">
                <div class="field">
                    <label for="subsystem_id">Subsistema <span aria-hidden="true">*</span></label>
                    <select id="subsystem_id" name="subsystem_id" required>
                        <option value="">Selecciona un subsistema</option>
                        @foreach ($subsystems as $subsystem)
                            <option value="{{ $subsystem->id }}" @selected(old('subsystem_id') == $subsystem->id)>{{ $subsystem->nombre }}</option>
                        @endforeach
                    </select>
                    @error('subsystem_id')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="credencial_usuario">Credencial de usuario <span aria-hidden="true">*</span></label>
                    <input id="credencial_usuario" name="credencial_usuario" type="text" maxlength="255" value="{{ old('credencial_usuario') }}" required>
                    @error('credencial_usuario')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="external_account_id">ID externo</label>
                    <input id="external_account_id" name="external_account_id" type="text" maxlength="255" value="{{ old('external_account_id') }}">
                    @error('external_account_id')<small class="error-message">{{ $message }}</small>@enderror
                </div>

                <div class="field">
                    <label for="estado">Estado <span aria-hidden="true">*</span></label>
                    <select id="estado" name="estado" required>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(old('estado') === $status->value)>{{ $status->value }}</option>
                        @endforeach
                    </select>
                    @error('estado')<small class="error-message">{{ $message }}</small>@enderror
                </div>
            </div>

            <div class="form-actions">
                <a class="button button-secondary" href="{{ route('gestor-users.accounts.index', $gestorUser) }}">Cancelar</a>
                <button class="button button-primary" type="submit">Guardar cuenta</button>
            </div>
        </form>
    </section>
@endsection
