<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AnularTermoRequest extends FormRequest
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
            // Una anulación sin motivo no se puede explicar después a un
            // auditor: el termo anulado sigue existiendo y hay que saber por qué.
            'motivo_anulacion' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo_anulacion' => 'motivo de anulación',
        ];
    }
}
