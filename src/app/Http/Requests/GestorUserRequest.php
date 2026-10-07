<?php

namespace App\Http\Requests;

use App\Models\GestorUser;
use App\Rules\Cpf;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GestorUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El CPF se guarda siempre en dígitos, sin puntos ni guiones.
     *
     * Es la clave de búsqueda contra Adagio y una unique local, así que el
     * formato tiene que ser uno solo: si se guardara '123.456.789-01' la
     * búsqueda por 12345678901 no lo encontraría y el unique no detectaría que
     * esa misma persona ya está dada de alta con otro formato.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->cpf)) {
            $this->merge(['cpf' => preg_replace('/\D/', '', $this->cpf)]);
        }
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
                new Cpf,
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
            'subsystem_config' => ['nullable', 'array'],
            'subsystem_config.chatwoot.teams' => ['nullable', 'array'],
            'subsystem_config.chatwoot.teams.*' => ['integer', 'min:1'],
        ];
    }
}
