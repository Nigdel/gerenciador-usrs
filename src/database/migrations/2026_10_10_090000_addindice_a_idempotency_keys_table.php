<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * La tabla ya tenía un índice único en `key` por la propia migración que
     * la crea. Este añade dos cosas que la conciliación de peticiones necesita:
     *
     * - `respuesta_hash` nullable, para distinguir «una petición que se está
     *   procesando ahora mismo» de «una cuyo resultado ya se guardó». Sin él no
     *   hay forma de saber si un 425 es un reintento seguro o una respuesta
     *   que ya se puede repetir tal cual.
     * - `expires_at`, para que el comando de limpieza sepa qué es viejo y no
     *   tenga que depender de `created_at` junto al TTL de configuración.
     */
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->string('respuesta_hash')->nullable()->after('provisioning_operation_id');
            $table->timestamp('expires_at')->nullable()->after('respuesta_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn(['respuesta_hash', 'expires_at']);
        });
    }
};
