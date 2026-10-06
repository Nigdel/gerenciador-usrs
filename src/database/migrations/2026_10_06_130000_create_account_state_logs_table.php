<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_state_logs', function (Blueprint $table) {
            $table->id();

            // Las tres referencias son SET NULL y no CASCADE a propósito: el
            // borrado de una cuenta es uno de los eventos que se quiere
            // registrar, así que su histórico tiene que sobrevivirle.
            $table->unsignedBigInteger('user_subsystem_account_id')->nullable();
            $table->unsignedBigInteger('gestor_user_id')->nullable();
            $table->unsignedBigInteger('subsystem_id')->nullable();

            $table->string('evento');             // created | updated | deleted
            $table->json('cambios')->nullable();   // {atributo: {desde, hasta}}

            $table->unsignedBigInteger('actor_id')->nullable();
            // Se congela el nombre del operador: si el usuario se borra, la
            // entrada no debe quedarse sin autor legible.
            $table->string('actor_nombre')->nullable();

            $table->string('origen');              // web | api | scheduler
            $table->string('descripcion')->nullable();

            $table->timestamps();

            $table->foreign('user_subsystem_account_id')
                ->references('id')->on('user_subsystem_accounts')->nullOnDelete();
            $table->foreign('gestor_user_id')
                ->references('id')->on('gestor_users')->nullOnDelete();
            $table->foreign('subsystem_id')
                ->references('id')->on('subsystems')->nullOnDelete();
            $table->foreign('actor_id')
                ->references('id')->on('users')->nullOnDelete();

            // gestor_user_id va desnormalizado para poder listar el histórico de
            // un usuario sin join, ya que su cuenta puede no existir.
            $table->index(['gestor_user_id', 'created_at']);
            $table->index(['user_subsystem_account_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_state_logs');
    }
};
