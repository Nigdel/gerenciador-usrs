<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja el CPF de gestor_users siempre en dígitos.
 *
 * Cierra D4. La columna es unique y la búsqueda contra Adagio usa el CPF
 * limpio, así que conviven dos formatos solo si nadie los mezcla: guardado con
 * puntos, buscado sin ellos, el alta de la misma persona dos veces con distinto
 * formato pasa el unique y duplica el registro.
 *
 * down() no deshace nada a propósito: quitar los dígitos a los que nunca los
 * tuvieron no es reversible, y un down() que finge lo contrario sería peor que
 * uno que lo dice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('gestor_users')) {
            return;
        }

        $filas = DB::table('gestor_users')->select('id', 'cpf')->get();

        $porNormalizar = [];

        foreach ($filas as $fila) {
            $cpf = (string) $fila->cpf;
            $soloDigitos = preg_replace('/\D/', '', $cpf);

            if ($soloDigitos !== $cpf) {
                $porNormalizar[$fila->id] = $soloDigitos;
            }
        }

        if ($porNormalizar === []) {
            return;
        }

        // Normalizar puede hacer que dos filas que eran distintas choquen bajo
        // el índice unique: '123.456.789-01' y '12345678901' son la misma
        // persona escrita de dos maneras. Se comprueba antes de escribir nada,
        // porque a mitad de la actualización el error sería una violación de
        // índice que no dice qué filas son las responsables.
        $colisiones = [];

        foreach ($porNormalizar as $id => $normalizado) {
            $otraId = DB::table('gestor_users')
                ->where('cpf', $normalizado)
                ->where('id', '!=', $id)
                ->value('id');

            if ($otraId !== null) {
                $colisiones[] = "id {$id} y id {$otraId} quedarían ambos en {$normalizado}";
            }
        }

        if ($colisiones !== []) {
            throw new RuntimeException(
                'No se puede normalizar el CPF: estos registros colisionarían al quitar la '
                .'puntuación. Revísalos a mano antes de migrar: '.implode('; ', $colisiones)
            );
        }

        foreach ($porNormalizar as $id => $normalizado) {
            DB::table('gestor_users')->where('id', $id)->update(['cpf' => $normalizado]);
        }
    }

    public function down(): void
    {
        // Ver la nota de clase: la puntuación original no se puede recuperar.
    }
};
