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
        Schema::table('subsystems', function (Blueprint $table) {
            $table->timestamp('last_connection_test_at')->nullable()->after('es_proveedor_identidad');
            $table->boolean('last_connection_test_success')->nullable()->after('last_connection_test_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subsystems', function (Blueprint $table) {
            $table->dropColumn(['last_connection_test_at', 'last_connection_test_success']);
        });
    }
};
