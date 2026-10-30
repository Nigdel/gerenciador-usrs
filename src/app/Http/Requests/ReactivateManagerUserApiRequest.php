<?php

namespace App\Http\Requests;

use App\Enums\ApiAbility;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;

class ReactivateManagerUserApiRequest extends FormRequest
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
        ];
    }
}
