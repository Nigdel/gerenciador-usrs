<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GestorUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre_completo' => $this->nombre_completo,
            'cpf' => $this->cpf,
            'usuario' => $this->usuario,
            'empresa' => $this->empresa,
            'email_personal' => $this->email_personal,
            'telefono_personal' => $this->telefono_personal,
            'telefono_trabajo' => $this->telefono_trabajo,
            'direccion_particular' => $this->direccion_particular,
            'subsistemas' => $this->whenLoaded('subsystemAccounts', fn () => $this->subsystemAccounts->map(fn ($cuenta) => [
                'subsistema' => $cuenta->subsystem->slug,
                'credencial_usuario' => $cuenta->credencial_usuario,
                'estado' => $cuenta->estado->value,
                'fecha_creacion' => $cuenta->fecha_creacion,
                'inicio_suspension' => $cuenta->inicio_suspension,
                'fin_suspension' => $cuenta->fin_suspension,
                'motivo_suspension' => $cuenta->motivo_suspension,
            ])),
        ];
    }
}
