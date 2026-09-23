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
    ];

    protected $casts = [
        'api_config' => 'array',
        'activo' => 'boolean',
        'es_proveedor_identidad' => 'boolean',
    ];

    protected $hidden = [
        'api_config', // puede contener tokens/credenciales sensibles
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(UserSubsystemAccount::class);
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