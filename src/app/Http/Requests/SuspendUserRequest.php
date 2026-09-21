<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cpf' => ['required_without:usuario', 'string', 'max:20'],
            'usuario' => ['required_without:cpf', 'string', 'max:255'],
            'subsistemas' => ['sometimes', 'array'],
            'subsistemas.*' => ['string', 'exists:subsystems,slug'],
            'motivo_suspension' => ['required', 'string', 'max:500'],
            'inicio_suspension' => ['sometimes', 'date'],
            'fin_suspension' => ['sometimes', 'nullable', 'date', 'after:inicio_suspension'],
        ];
    }
}
