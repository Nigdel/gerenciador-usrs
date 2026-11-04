<?php

namespace Tests\Unit;

use App\Services\Import\GlpiRemoteAccountEnumerator;
use Tests\TestCase;

class GlpiRemoteAccountEnumeratorTest extends TestCase
{
    public function test_extrae_cpf_con_formato_puntos_y_guiones(): void
    {
        $enumerador = new GlpiRemoteAccountEnumerator;

        $this->assertSame('06061123590', $enumerador->cpfDelComment('CPF: 060.611.235-90'));
    }

    public function test_extrae_cpf_sin_formato(): void
    {
        $enumerador = new GlpiRemoteAccountEnumerator;

        $this->assertSame('12345678901', $enumerador->cpfDelComment('cpf: 12345678901'));
    }

    public function test_retorna_null_cuando_no_hay_cpf(): void
    {
        $enumerador = new GlpiRemoteAccountEnumerator;

        $this->assertNull($enumerador->cpfDelComment('Sin identificador de propietario'));
    }
}
