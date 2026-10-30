<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class SuspendManagerUserApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tokenCan(ApiAbility::Suspender->value) ?? false;
    }

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
            'motivo_suspension' => ['required', 'string', 'min:5', 'max:500'],
            'inicio_suspension' => ['required_with:fin_suspension', 'date'],
            'fin_suspension' => [
                'sometimes',
                'nullable',
                'date',
                'after_or_equal:inicio_suspension',
                'after_or_equal:today',
            ],
        ];
    }
}
