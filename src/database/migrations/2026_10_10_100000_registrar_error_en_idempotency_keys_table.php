<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Una petición que falla con 422 (payload no válido) no ha hecho nada, así
     * que su fila no debe quedarse ocupando la clave: si la integración
     * corrige el campo y reintenta con la MISMA clave, tiene que poder. Por eso
     * la fila se borra al responder con error y no hay columna para guardarlo:
     * el historial de errores vive en los logs, no aquí.
     *
     * El 502 es distinto. Ahí el fallo es de infraestructura y la operación
     * puede que se haya creado; repetir la respuesta de error sería mentir,
     * pero borrar la fila haría que un reintento duplicara el alta. Por eso un
     * 502 sí se guarda, con su código, y se repite tal cual.
     */
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->unsignedSmallInteger('status')->nullable()->after('respuesta_hash');
            $table->text('error')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn(['status', 'error']);
        });
    }
};
