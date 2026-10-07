<?php

namespace App\Http\Requests;

use App\Enums\GestorUserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros del listado de usuarios.
 *
 * Validar en el borde lo que se devuelve como HTML evita que un ?estado=activo%
20 UNION llegue a la consulta con forma de enumeración válida. Rule::enum hace
 * que estado sea un GestorUserStatus o null, nunca una cadena cualquiera.
 */
class GestorUserListRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'estado' => ['nullable', Rule::enum(GestorUserStatus::class)],
            'subsistema' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Atajos tipados para el controlador: si el valor no pasó la validación
     * llega como null, y null en los scopes significa "no filtrar".
     */
    public function estado(): ?GestorUserStatus
    {
        $estado = $this->validated('estado');

        return is_string($estado) ? GestorUserStatus::tryFrom($estado) : null;
    }

    public function busqueda(): ?string
    {
        $q = $this->validated('q');

        return is_string($q) && $q !== '' ? $q : null;
    }

    public function subsistema(): ?string
    {
        $slug = $this->validated('subsistema');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }
}
