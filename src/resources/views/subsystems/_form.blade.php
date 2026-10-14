@csrf
@if ($subsystem?->exists)
    @method('PUT')
@endif

<div class="grid gap-6 sm:grid-cols-2">
    <div class="">
        <label class="{{ $label }}" for="nombre">Nombre <span aria-hidden="true" class="text-red-500">*</span></label>
        <input id="nombre" name="nombre" type="text" maxlength="255" class="{{ $input }}" value="{{ old('nombre', $subsystem?->nombre) }}" required>
        @error('nombre')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>

    <div class="">
        <label class="{{ $label }}" for="slug">Slug <span aria-hidden="true" class="text-red-500">*</span></label>
        <input id="slug" name="slug" type="text" maxlength="255" pattern="[A-Za-z0-9_-]+" class="{{ $input }}" value="{{ old('slug', $subsystem?->slug) }}" required>
        <small class="{{ $hint }}">Usa letras, números, guiones o guiones bajos.</small>
        @error('slug')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>

    <div class="sm:col-span-2">
        <label class="{{ $label }}" for="descripcion">Descripción</label>
        <textarea id="descripcion" name="descripcion" rows="4" class="{{ $input }}" style="min-height: 120px;">{{ old('descripcion', $subsystem?->descripcion) }}</textarea>
        @error('descripcion')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>

    <div class="">
        <label class="{{ $label }}" for="api_url">URL de la API</label>
        <input id="api_url" name="api_url" type="url" maxlength="255" placeholder="https://api.ejemplo.com" class="{{ $input }}" value="{{ old('api_url', $subsystem?->api_url) }}">
        @error('api_url')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>

    <div class="">
        <label class="{{ $label }}" for="external_subsystem_id">ID externo</label>
        <input id="external_subsystem_id" name="external_subsystem_id" type="text" maxlength="255" class="{{ $input }}" value="{{ old('external_subsystem_id', $subsystem?->external_subsystem_id) }}">
        @error('external_subsystem_id')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>

    <div class="sm:col-span-2">
        <label class="{{ $label }}" for="api_config">Configuración API (JSON)</label>
        <textarea id="api_config" name="api_config" rows="6" spellcheck="false" placeholder="{ &quot;timeout&quot;: 30 }" class="{{ $input }}" style="min-height: 200px; font-family: monospace;">{{ old('api_config', $subsystem?->api_config ? json_encode($subsystem->api_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '') }}</textarea>
        <small class="{{ $hint }}">No incluyas credenciales reales en entornos compartidos.</small>
        @error('api_config')<small class="{{ $err }}">{{ $message }}</small>@enderror
    </div>
</div>

<div class="mt-6 flex flex-wrap gap-4">
    <label class="inline-flex items-center gap-2 text-sm font-medium text-[#17211b] cursor-pointer" for="activo">
        <input id="activo" name="activo" type="checkbox" value="1" class="rounded border-[#d9e2dc] text-emerald-600 focus:ring-emerald-600" @checked(old('activo', $subsystem?->activo ?? true))>
        <span>Subsistema activo</span>
    </label>
    <label class="inline-flex items-center gap-2 text-sm font-medium text-[#17211b] cursor-pointer" for="es_proveedor_identidad">
        <input id="es_proveedor_identidad" name="es_proveedor_identidad" type="checkbox" value="1" class="rounded border-[#d9e2dc] text-emerald-600 focus:ring-emerald-600" @checked(old('es_proveedor_identidad', $subsystem?->es_proveedor_identidad ?? false))>
        <span>Es proveedor de identidad</span>
    </label>
</div>

<div class="mt-10 flex items-center gap-3 border-t border-[#d9e2dc] pt-6">
    <a class="{{ $btnSecondary }}" href="{{ route('subsystems.index') }}">Cancelar</a>
    <button class="{{ $btnPrimary }}" type="submit">{{ $subsystem?->exists ? 'Guardar cambios' : 'Crear subsistema' }}</button>
</div>
