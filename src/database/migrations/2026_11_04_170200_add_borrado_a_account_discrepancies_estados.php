<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * El estado remoto que reporta un subsistema no se limita a las cuentas
     * vivas: GLPI, por ejemplo, reporta 'borrado' cuando el usuario fue
     * eliminado. Las columnas de discrepancias se crearon con un enum que
     * solo admitía 'activo'/'suspendido'/'deshabilitado', así que registrar
     * una discrepancia contra una cuenta borrada en remoto hacía fallar el
     * insert con "Data truncated for column" en MySQL.
     */
    public function up(): void
    {
        Schema::table('account_discrepancies', function (Blueprint $table) {
            $table->enum('remote_estado', ['activo', 'suspendido', 'deshabilitado', 'borrado'])->nullable()->change();
            $table->enum('estado_local', ['activo', 'suspendido', 'deshabilitado', 'borrado'])->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_discrepancies', function (Blueprint $table) {
            $table->enum('remote_estado', ['activo', 'suspendido', 'deshabilitado'])->nullable()->change();
            $table->enum('estado_local', ['activo', 'suspendido', 'deshabilitado'])->nullable()->change();
        });
    }
};
