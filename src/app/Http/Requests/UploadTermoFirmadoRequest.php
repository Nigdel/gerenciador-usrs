<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadTermoFirmadoRequest extends FormRequest
{
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
            // `mimes` comprueba el MIME real del archivo, no solo el nombre:
            // un ejecutable renombrado a .pdf no pasa.
            'pdf_firmado' => [
                'required',
                'file',
                'mimes:pdf',
                'max:'.(int) config('termos.max_pdf_kb', 10240),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'pdf_firmado' => 'PDF firmado',
        ];
    }
}
