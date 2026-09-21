<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gestor_users', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_completo');
            $table->string('cpf', 20)->unique();
            $table->string('password_general'); // se almacena hasheada (Hash::make)
            $table->string('telefono_personal')->nullable();
            $table->string('telefono_trabajo')->nullable();
            $table->string('email_personal')->nullable();
            $table->string('direccion_particular')->nullable();
            $table->string('usuario')->unique(); // login usado en todos los subsistemas
            $table->string('empresa');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestor_users');
    }
};
