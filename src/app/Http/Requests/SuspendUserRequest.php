<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use Illuminate\Foundation\Http\FormRequest;

class SuspendUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Defence en profundidad: la ruta ya exige el middleware
        // abilities:usuarios:suspender. Esto cubre el caso de que el
        // controlador se use desde otra entrada.
        return $this->user()?->tokenCan(ApiAbility::Suspender->value) ?? false;
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
