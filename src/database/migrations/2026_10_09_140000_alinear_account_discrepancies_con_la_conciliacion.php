<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * La conciliación (accounts:reconcile) compara el estado guardado de una
     * cuenta con el que dice el subsistema. Eso no cuelga de ninguna
     * ProvisioningOperation — la cuenta lleva días funcionando y quien lo
     * detecta es el comando programado, no una acción de un operador — así
     * que la operación pasa a ser opcional.
     *
     * Y el estado local que hay que guardar es el de la cuenta en el
     * subsistema (activo/suspendido/deshabilitado), no el avance de una fila
     * de operación (pendiente/ok/error) que es lo que ya guardaba
     * local_estado. Por eso se añade estado_local con los mismos valores que
     * remote_estado: son las dos caras de la misma comparación.
     */
    public function up(): void
    {
        Schema::table('account_discrepancies', function (Blueprint $table) {
            $table->foreignId('provisioning_operation_id')
                ->nullable()
                ->change();

            $table->enum('estado_local', ['activo', 'suspendido', 'deshabilitado'])->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account_discrepancies', function (Blueprint $table) {
            $table->dropColumn('estado_local');

            $table->foreignId('provisioning_operation_id')
                ->nullable(false)
                ->change();
        });
    }
};
