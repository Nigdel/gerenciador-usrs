<?php

namespace App\Http\Requests;

use App\Enums\TermoTipo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTermoRequest extends FormRequest
{
    /**
     * La autorización real la hace la policy (ability 'generarTermo'). Aquí
     * solo se declara porque la ruta exige sesión.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // El tipo y el id llegan del listado de medios: son la referencia
            // al equipo en GLPI, no datos que el formulario pueda inventar.
            'item_type' => ['required', 'string', Rule::in([
                'Computer',
                'Peripheral',
                'Phone',
                'Monitor',
                'Printer',
                'NetworkEquipment',
            ])],
            'item_id' => ['required', 'integer', 'min:1'],

            'tipo' => ['required', Rule::enum(TermoTipo::class)],

            // El CNPJ se imprime tal como lo escribe el operador. No se valida
            // el dígito verificador: el documento es una constancia y una
            // corrección de formato no puede impedir entregar un equipo.
            'cnpj' => ['nullable', 'string', 'max:20'],
            'procesador' => ['nullable', 'string', 'max:150'],
            'color' => ['nullable', 'string', 'max:50'],
            'ram' => ['nullable', 'string', 'max:50'],
            'almacenamiento' => ['nullable', 'string', 'max:50'],
            'mac' => ['nullable', 'string', 'max:100'],
            'estado_conservacion' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_type' => 'tipo de medio',
            'item_id' => 'medio',
            'tipo' => 'tipo de termo',
            'cnpj' => 'CNPJ',
            'estado_conservacion' => 'estado de conservación',
            'observaciones' => 'observaciones',
        ];
    }
}
