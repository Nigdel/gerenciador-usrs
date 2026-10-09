<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account_discrepancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provisioning_operation_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_subsystem_account_id')->constrained()->onDelete('cascade');
            $table->string('subsystem');
            $table->enum('local_estado', ['pendiente', 'ok', 'error'])->default('pendiente');
            $table->enum('remote_estado', ['activo', 'suspendido', 'deshabilitado'])->nullable();
            $table->dateTime('detectada_at')->useCurrent();
            $table->dateTime('resuelta_at')->nullable();
            $table->enum('resolucion', ['aceptar_remoto', 'forzar_local'])->nullable();
            $table->string('actor_origen')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_discrepancies');
    }
};
