<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreManyUserSubsystemAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gestorUserId = $this->route('gestorUser')?->id;

        return [
            'subsystem_ids' => ['required', 'array', 'min:1'],
            'subsystem_ids.*' => [
                'integer',
                'distinct',
                // Solo se pueden vincular subsistemas activos y en los que el
                // gestor todavía no tenga cuenta.
                Rule::exists('subsystems', 'id')
                    ->where(fn ($query) => $query->where('activo', true)),
                Rule::unique('user_subsystem_accounts', 'subsystem_id')
                    ->where(fn ($query) => $query->where('gestor_user_id', $gestorUserId)),
            ],
            // Opcional: los drivers que necesitan contraseña la usan al crear
            // la cuenta. La del gestor no se puede reutilizar porque está
            // guardada con hash.
            'password_patron' => ['nullable', 'string', 'min:8', 'max:255'],
            // Configuración específica por subsistema. Hoy solo Chatwoot
            // necesita que el operador elija los equipos.
            'subsystem_config' => ['nullable', 'array'],
            'subsystem_config.chatwoot' => ['nullable', 'array'],
            'subsystem_config.chatwoot.teams' => ['nullable', 'array'],
            'subsystem_config.chatwoot.teams.*' => ['integer', 'min:1'],
        ];
    }
}
