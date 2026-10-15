<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gestor_users', function (Blueprint $table) {
            $table->timestamp('fin_suspension_gestor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gestor_users', function (Blueprint $table) {
            $table->dropColumn('fin_suspension_gestor');
        });
    }
};
