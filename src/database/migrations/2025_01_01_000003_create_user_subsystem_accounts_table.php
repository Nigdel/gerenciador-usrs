<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_subsystem_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gestor_user_id')->constrained('gestor_users')->cascadeOnDelete();
            $table->foreignId('subsystem_id')->constrained('subsystems')->cascadeOnDelete();

            $table->string('credencial_usuario');           // usuario/login usado en ese subsistema
            $table->string('external_account_id')->nullable(); // id de la cuenta devuelto por la API del subsistema

            $table->timestamp('fecha_creacion')->nullable();
            $table->string('estado')->default('activo');    // activo | deshabilitado | suspendido

            $table->timestamp('inicio_suspension')->nullable();
            $table->timestamp('fin_suspension')->nullable();
            $table->text('motivo_suspension')->nullable();

            $table->json('meta')->nullable(); // payload crudo devuelto por la API, útil para auditoría/debug

            $table->timestamps();

            $table->unique(['gestor_user_id', 'subsystem_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subsystem_accounts');
    }
};
