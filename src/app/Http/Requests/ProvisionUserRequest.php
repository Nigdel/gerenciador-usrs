<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class ProvisionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defence en profundidad: la ruta ya exige el middleware
        // abilities:usuarios:provisionar. Esto cubre el caso de que el
        // controlador se use desde otra entrada.
        return $this->user()?->tokenCan(ApiAbility::Provisionar->value) ?? false;
    }

    /**
     * Mismo criterio que en GestorUserRequest: el CPF entra en dígitos, sin
     * puntuación, para que lo que se busca en Adagio y lo que se guarda aquí
     * sean la misma cadena.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->cpf)) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->cpf)]);
        }
    }

    public function rules(): array
    {
        return [
            'cpf' => ['required', 'string', 'max:20', new Cpf],
            'nombre_completo' => ['sometimes', 'required', 'string', 'max:255'],
            'password_general' => ['sometimes', 'string', 'min:8'],
            'telefono_personal' => ['sometimes', 'nullable', 'string', 'max:30'],
            'telefono_trabajo' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email_personal' => ['sometimes', 'nullable', 'email', 'max:255'],
            'direccion_particular' => ['sometimes', 'nullable', 'string', 'max:255'],
            'empresa' => ['sometimes', 'required', 'string', 'max:255'],
            'usuario' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subsistemas' => ['sometimes', 'array'],
            'subsistemas.*' => ['string', 'exists:subsystems,slug'],
        ];
    }
}
