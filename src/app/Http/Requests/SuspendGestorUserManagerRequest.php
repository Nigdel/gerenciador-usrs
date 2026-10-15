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
        return [
            'motivo_suspension_gestor' => ['required', 'string', 'min:5', 'max:500'],
            'fin_suspension_gestor' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_suspension_gestor' => 'motivo de la suspensión del gestor',
            'fin_suspension_gestor' => 'fecha de fin de la suspensión',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fin_suspension_gestor.after_or_equal' => 'La fecha de fin no puede ser anterior a hoy.',
        ];
    }
}
