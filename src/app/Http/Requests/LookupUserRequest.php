<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class LookupUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defensa en profundidad: la ruta ya exige la ability
        // usuarios:consultar. Esto cubre el caso de que el controlador se use
        // desde otra entrada.
        return $this->user()?->tokenCan(ApiAbility::ConsultarUsuario->value) ?? false;
    }

    /**
     * Mismo criterio que en el resto de la API: el CPF entra en dígitos, sin
     * puntuación, para que lo que se busca en el sistema y en Adagio sea la
     * misma cadena.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->cpf)) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->cpf)]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'cpf' => ['required', 'string', 'max:20', new Cpf],
        ];
    }
}
