<?php

namespace App\Http\Requests;

use App\Models\GestorUser;
use App\Models\UserSubsystemAccount;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos del formulario de suspensión en la web.
 *
 * El servicio UserSuspensionService ya es el orquestador y la API lo usa con
 * estas mismas reglas; aquí cambia quién autoriza: la policy `suspend`
 * (admin + operador) en lugar de la ability de la API.
 */
class SuspendGestorUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gestorUser = $this->route('gestorUser');

        return $gestorUser instanceof GestorUser
            && $this->user()?->can('suspend', $gestorUser) === true;
    }

    public function rules(): array
    {
        return [
            // Vacío significa "todos los subsistemas donde tenga cuenta", que es
            // el comportamiento por defecto del UserSuspensionService.
            'subsistemas' => ['sometimes', 'nullable', 'array'],
            'subsistemas.*' => ['string', $this->slugDeCuentaPropia()],
            'motivo_suspension' => ['required', 'string', 'max:500'],
            'inicio_suspension' => ['sometimes', 'nullable', 'date'],
            'fin_suspension' => ['sometimes', 'nullable', 'date', 'after_or_equal:inicio_suspension'],
        ];
    }

    /**
     * Solo se pueden suspender cuentas que el usuario tenga en ese subsistema.
     */
    private function slugDeCuentaPropia(): Closure
    {
        $gestorUserId = $this->route('gestorUser')?->id;

        return function (string $attribute, mixed $value, Closure $fail) use ($gestorUserId): void {
            $existe = UserSubsystemAccount::query()
                ->where('gestor_user_id', $gestorUserId)
                ->whereHas('subsystem', fn ($q) => $q->where('slug', $value))
                ->exists();

            if (! $existe) {
                $fail("El usuario no tiene cuenta en el subsistema «{$value}».");
            }
        };
    }

    public function messages(): array
    {
        return [
            'motivo_suspension.required' => 'Indica el motivo de la suspensión.',
            'fin_suspension.after_or_equal' => 'El fin no puede ser anterior al inicio.',
        ];
    }
}
