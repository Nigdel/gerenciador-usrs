@csrf
@if ($subsystem?->exists)
    @method('PUT')
@endif

<div class="field-grid">
    <div class="field">
        <label for="nombre">Nombre <span aria-hidden="true">*</span></label>
        <input id="nombre" name="nombre" type="text" maxlength="255" value="{{ old('nombre', $subsystem?->nombre) }}" required>
        @error('nombre')<small class="error-message">{{ $message }}</small>@enderror
    </div>

    <div class="field">
        <label for="slug">Slug <span aria-hidden="true">*</span></label>
        <input id="slug" name="slug" type="text" maxlength="255" pattern="[A-Za-z0-9_-]+" value="{{ old('slug', $subsystem?->slug) }}" required>
        <small class="field-hint">Usa letras, números, guiones o guiones bajos.</small>
        @error('slug')<small class="error-message">{{ $message }}</small>@enderror
    </div>

    <div class="field field-wide">
        <label for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion" rows="4">{{ old('descripcion', $subsystem?->descripcion) }}</textarea>
        @error('descripcion')<small class="error-message">{{ $message }}</small>@enderror
    </div>

    <div class="field">
        <label for="api_url">URL de la API</label>
        <input id="api_url" name="api_url" type="url" maxlength="255" placeholder="https://api.ejemplo.com" value="{{ old('api_url', $subsystem?->api_url) }}">
        @error('api_url')<small class="error-message">{{ $message }}</small>@enderror
    </div>

    <div class="field">
        <label for="external_subsystem_id">ID externo</label>
        <input id="external_subsystem_id" name="external_subsystem_id" type="text" maxlength="255" value="{{ old('external_subsystem_id', $subsystem?->external_subsystem_id) }}">
        @error('external_subsystem_id')<small class="error-message">{{ $message }}</small>@enderror
    </div>

    <div class="field field-wide">
        <label for="api_config">Configuración API (JSON)</label>
        <textarea id="api_config" name="api_config" rows="6" spellcheck="false" placeholder="{ &quot;timeout&quot;: 30 }">{{ old('api_config', $subsystem?->api_config ? json_encode($subsystem->api_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
        <small class="field-hint">No incluyas credenciales reales en entornos compartidos.</small>
        @error('api_config')<small class="error-message">{{ $message }}</small>@enderror
    </div>
</div>

<div class="checkbox-group">
    <label class="checkbox-field" for="activo">
        <input id="activo" name="activo" type="checkbox" value="1" @checked(old('activo', $subsystem?->activo ?? true))>
        <span>Subsistema activo</span>
    </label>
    <label class="checkbox-field" for="es_proveedor_identidad">
        <input id="es_proveedor_identidad" name="es_proveedor_identidad" type="checkbox" value="1" @checked(old('es_proveedor_identidad', $subsystem?->es_proveedor_identidad ?? false))>
        <span>Es proveedor de identidad</span>
    </label>
</div>

<div class="form-actions">
    <a class="button button-secondary" href="{{ route('subsystems.index') }}">Cancelar</a>
    <button class="button button-primary" type="submit">{{ $subsystem?->exists ? 'Guardar cambios' : 'Crear subsistema' }}</button>
</div>
