<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida un CPF con sus dos dígitos verificadores.
 *
 * El CPF son once dígitos: los nueve primeros son la base y los dos últimos se
 * calculan a partir de ellos. La regla espera los dígitos ya normalizados —
 * quien la usa tiene que quitar puntos y guiones antes (véase
 * prepareForValidation() en los formularios), porque el cálculo no se puede
 * hacer sobre un texto con puntuación.
 *
 * Se rechazan las secuencias de dígitos repetidos (11111111111 y así): tienen
 * los dígitos verificadores correctos y son CPFs que la Receita Federal nunca
 * emite, pero que un cálculo de módulo los daría por buenos.
 */
class Cpf implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('El CPF debe ser un texto con once dígitos.');

            return;
        }

        // Vacío no es asunto de esta regla: lo cubren required y max. Fallar
        // aquí duplicaría el mensaje de error del formulario.
        if ($value === '') {
            return;
        }

        if (! $this->esValido($value)) {
            $fail('El CPF no es válido.');
        }
    }

    /**
     * ¿El CPF tiene once dígitos, no es una secuencia repetida y sus dígitos
     * verificadores cuadran?
     */
    public function esValido(string $cpf): bool
    {
        if (preg_match('/^\d{11}$/', $cpf) !== 1) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($digito = 10; $digito <= 11; $digito++) {
            if ($this->digitoVerificador($cpf, $digito) !== (int) $cpf[$digito - 1]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calcula el dígito verificador número $digito (1-indexado, de 1 a 11).
     *
     * Cada dígito se multiplica por un peso que baja de $digito a 2, se suman,
     * y el resto de esa suma entre 11 se transforma con la regla oficial: 0 o 1
     * dan 0, y del 2 al 10 se resta de 11.
     */
    private function digitoVerificador(string $cpf, int $digito): int
    {
        $suma = 0;

        for ($i = 0; $i < $digito - 1; $i++) {
            $suma += (int) $cpf[$i] * ($digito - $i);
        }

        $resto = $suma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
