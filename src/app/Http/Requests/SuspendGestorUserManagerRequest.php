<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuspendGestorUserManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $fechaFin = ['sometimes', 'nullable', 'date', 'after_or_equal:today'];

        if ($this->filled('inicio_suspension_gestor')) {
            $fechaFin[] = 'after_or_equal:inicio_suspension_gestor';
        }

        return [
            'motivo_suspension_gestor' => ['required', 'string', 'min:5', 'max:500'],
            'inicio_suspension_gestor' => ['sometimes', 'nullable', 'date'],
            'fin_suspension_gestor' => $fechaFin,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_suspension_gestor' => 'motivo de la suspensión del gestor',
            'inicio_suspension_gestor' => 'fecha de inicio de la suspensión',
            'fin_suspension_gestor' => 'fecha de fin de la suspensión',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fin_suspension_gestor.after_or_equal' => 'La fecha de fin no puede ser anterior a hoy ni a la fecha de inicio.',
        ];
    }
}
