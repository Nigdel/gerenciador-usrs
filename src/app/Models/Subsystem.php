<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subsystem extends Model
{
    use HasFactory;

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'api_url',
        'api_config',
        'external_subsystem_id',
        'activo',
        'es_proveedor_identidad',
        'last_connection_test_at',
        'last_connection_test_success',
    ];

    protected $casts = [
        'api_config' => 'array',
        'activo' => 'boolean',
        'es_proveedor_identidad' => 'boolean',
        'last_connection_test_at' => 'datetime',
        'last_connection_test_success' => 'boolean',
    ];

    protected $hidden = [
        'api_config', // puede contener tokens/credenciales sensibles
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(UserSubsystemAccount::class);
    }

    public function getAccessUrlAttribute(): ?string
    {
        $apiConfig = is_array($this->api_config) ? $this->api_config : [];
        $url = data_get($apiConfig, 'url') ?: $this->api_url;

        return filled($url) ? trim((string) $url) : null;
    }

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeProveedorIdentidad($query)
    {
        return $query->where('es_proveedor_identidad', true);
    }
}
