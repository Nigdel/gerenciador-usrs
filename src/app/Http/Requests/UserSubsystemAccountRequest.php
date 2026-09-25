<?php

namespace App\Http\Requests;

use App\Enums\SubsystemAccountStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserSubsystemAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $gestorUserId = $this->route('gestorUser')?->id;
        $accountId = $this->route('userSubsystemAccount')?->id;

        return [
            'subsystem_id' => [
                'required',
                'integer',
                'exists:subsystems,id',
                Rule::unique('user_subsystem_accounts', 'subsystem_id')
                    ->where(fn ($query) => $query->where('gestor_user_id', $gestorUserId))
                    ->ignore($accountId),
            ],
            'credencial_usuario' => ['required', 'string', 'max:255'],
            'external_account_id' => ['nullable', 'string', 'max:255'],
            'estado' => ['required', Rule::in(array_map(fn (SubsystemAccountStatus $status) => $status->value, SubsystemAccountStatus::cases()))],
            'inicio_suspension' => ['nullable', 'date'],
            'fin_suspension' => ['nullable', 'date'],
            'motivo_suspension' => ['nullable', 'string', 'max:500'],
        ];
    }
}
