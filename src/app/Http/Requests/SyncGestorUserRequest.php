<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncGestorUserRequest extends FormRequest
{
    /**
     * La autorización real la hace la policy (ability 'update'), que es la misma
     * que protege el formulario de edición: sincronizar es propagar lo que el
     * operador ya puede cambiar, no una acción distinta.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Opcional a propósito: sin marcar ninguno se sincronizan todas las
            // cuentas del usuario, que es el caso normal del botón "Sincronizar".
            'subsistemas' => ['nullable', 'array'],
            'subsistemas.*' => [
                'string',
                Rule::exists('subsystems', 'slug')->where(fn ($query) => $query->where('activo', true)),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'subsistemas' => 'subsistemas',
        ];
    }
}
