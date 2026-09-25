<?php

namespace App\Enums;

enum SubsystemAccountStatus: string
{
    case Activo = 'activo';
    case Deshabilitado = 'deshabilitado';
    case Suspendido = 'suspendido';
    case Borrado = 'borrado';
}
