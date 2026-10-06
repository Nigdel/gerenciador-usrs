<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class OffboardGestorUserRequest extends FormRequest
{
    /**
     * La autorización real la hace la policy (ability 'offboard'), que exige
     * admin. Aquí solo se declara porque es una ruta autenticada.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Obligatorio: una baja sin motivo escrito no se puede explicar
            // después a un auditor ni al propio usuario.
            'motivo_baja' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_baja' => 'motivo de la baja',
        ];
    }
}
