<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProvisionUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Ajustar según tu política/gate de autorización real.
    }

    public function rules(): array
    {
        return [
            'cpf' => ['required', 'string', 'max:20'],
            'nombre_completo' => ['sometimes', 'required', 'string', 'max:255'],
            'password_general' => ['sometimes', 'string', 'min:8'],
            'telefono_personal' => ['sometimes', 'nullable', 'string', 'max:30'],
            'telefono_trabajo' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email_personal' => ['sometimes', 'nullable', 'email', 'max:255'],
            'direccion_particular' => ['sometimes', 'nullable', 'string', 'max:255'],
            'empresa' => ['sometimes', 'required', 'string', 'max:255'],
            'usuario' => ['sometimes', 'nullable', 'string', 'max:255'],
            'subsistemas' => ['sometimes', 'array'],
            'subsistemas.*' => ['string', 'exists:subsystems,slug'],
        ];
    }
}
