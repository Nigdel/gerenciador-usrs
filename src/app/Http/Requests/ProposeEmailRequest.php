<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class ProposeEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defensa en profundidad: la ruta ya exige la ability
        // usuarios:consultar. Esto cubre el caso de que el controlador se use
        // desde otra entrada.
        return $this->user()?->tokenCan(ApiAbility::ConsultarUsuario->value) ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nombre_completo' => [
                'required',
                'string',
                'max:255',
                // El generador necesita nombre y al menos un apellido para
                // construir el login. Se valida aquí para que la integración
                // reciba un 422 sobre el campo y no un error de dominio.
                function (string $attribute, mixed $value, Closure $fail): void {
                    $partes = preg_split('/\s+/', trim((string) $value)) ?: [];

                    if (count($partes) < 2) {
                        $fail('El nombre completo debe incluir al menos nombre y un apellido.');
                    }
                },
            ],
            'empresa' => ['required', 'string', 'max:255'],
        ];
    }
}
