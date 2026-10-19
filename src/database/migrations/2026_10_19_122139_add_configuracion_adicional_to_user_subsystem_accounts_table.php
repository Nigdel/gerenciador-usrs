<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subsystem_accounts', function (Blueprint $table) {
            // Información propia del subsistema que no encaja en las columnas
            // comunes: equipos e inboxes de Chatwoot, perfil de GLPI, flags de
            // administrador en SambaAD, etc.
            $table->json('configuracion_adicional')->nullable()->after('meta');
        });
    }

    public function down(): void
    {
        Schema::table('user_subsystem_accounts', function (Blueprint $table) {
            $table->dropColumn('configuracion_adicional');
        });
    }
};
