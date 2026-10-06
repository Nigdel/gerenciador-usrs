<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gestor_users', function (Blueprint $table) {
            // Fase 2.6. Es una columna y no un SoftDeletes a propósito: dar de
            // baja no es borrar, y el histórico de sus cuentas tiene que seguir
            // consultable. Con deleted_at el usuario desaparecería de las
            // consultas justo cuando más falta hace ver qué se le hizo.
            $table->string('estado')->default('activo')->after('empresa');

            // Cuándo y por qué se dio de baja. El motivo va aquí y no solo en el
            // histórico porque es lo que se consulta al responder "¿por qué este
            // empleado no tiene acceso?".
            $table->timestamp('baja_at')->nullable();
            $table->text('motivo_baja')->nullable();

            // El índice acompaña a la columna porque el listado filtra por ella y
            // la tabla crece con cada baja.
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::table('gestor_users', function (Blueprint $table) {
            $table->dropIndex(['estado']);
            $table->dropColumn(['estado', 'baja_at', 'motivo_baja']);
        });
    }
};
