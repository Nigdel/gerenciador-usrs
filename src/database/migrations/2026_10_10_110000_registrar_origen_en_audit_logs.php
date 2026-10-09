<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `account_state_logs` ya distingue web / api / scheduler desde la fase de
     * conciliación, porque sin eso una entrada no dice si la hizo un operador
     * o un proceso automático. `audit_logs` se escribía sin esa distinción y se
     * quedaba sin ella.
     *
     * Se añade como columna en vez de deducirlo de la ruta porque la ruta no
     * sobrevive al paso del tiempo: una entrada de dentro de seis meses solo
     * tiene sentido si el origen quedó guardado con ella.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('origen')->nullable()->after('action')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
