<?php

namespace App\Http\Requests;

use App\Enums\OperationStatus;
use App\Enums\OperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProvisioningOperationListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', Rule::enum(OperationStatus::class)],
            'tipo' => ['nullable', Rule::enum(OperationType::class)],
            'usuario' => ['nullable', 'string', 'max:255'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fim' => ['nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }

    public function estado(): ?OperationStatus
    {
        $estado = $this->validated('estado');

        return is_string($estado) ? OperationStatus::tryFrom($estado) : null;
    }

    public function tipo(): ?OperationType
    {
        $tipo = $this->validated('tipo');

        return is_string($tipo) ? OperationType::tryFrom($tipo) : null;
    }

    public function usuario(): ?string
    {
        $q = $this->validated('usuario');

        return is_string($q) && $q !== '' ? $q : null;
    }

    public function fechaInicio(): ?string
    {
        return $this->validated('fecha_inicio');
    }

    public function fechaFim(): ?string
    {
        return $this->validated('fecha_fim');
    }
}
