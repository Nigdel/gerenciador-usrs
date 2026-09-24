<?php

namespace App\Http\Requests;

use App\Models\GestorUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GestorUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var GestorUser|null $gestorUser */
        $gestorUser = $this->route('gestorUser');

        return [
            'nombre_completo' => ['required', 'string', 'max:255'],
            'cpf' => [
                'required',
                'string',
                'max:20',
                Rule::unique('gestor_users', 'cpf')->ignore($gestorUser?->id),
            ],
            'password_general' => [$gestorUser ? 'nullable' : 'required', 'string', 'min:8'],
            'telefono_personal' => ['nullable', 'string', 'max:30'],
            'telefono_trabajo' => ['nullable', 'string', 'max:30'],
            'email_personal' => ['nullable', 'email', 'max:255'],
            'direccion_particular' => ['nullable', 'string', 'max:255'],
            'usuario' => ['nullable', 'string', 'max:255'],
            'empresa' => ['required', 'string', 'max:255'],
            'subsistemas' => [$gestorUser ? 'nullable' : 'required', 'array', 'min:1'],
            'subsistemas.*' => [
                'string',
                Rule::exists('subsystems', 'slug')->where(fn ($query) => $query->where('activo', true)),
            ],
        ];
    }
}