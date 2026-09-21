<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subsystems', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();     // Adagio, Glpi, Chatwoot, Email, Slack, EntraId, SambaAd...
            $table->string('slug')->unique();        // adagio, glpi, chatwoot... -> mapea a la clase driver (config/subsystems.php)
            $table->text('descripcion')->nullable();
            $table->string('api_url')->nullable();   // endpoint base de la API del subsistema
            $table->json('api_config')->nullable();  // credenciales/tokens/headers/opciones propias del subsistema
            $table->string('external_subsystem_id')->nullable(); // "id de subsistema" en el sistema externo
            $table->boolean('activo')->default(true);
            $table->boolean('es_proveedor_identidad')->default(false); // true solo para Adagio
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subsystems');
    }
};
