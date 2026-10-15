<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gestor_users', function (Blueprint $table) {
            $table->timestamp('suspendido_at')->nullable();
            $table->text('motivo_suspension_gestor')->nullable();
        });

        Schema::create('gestor_user_suspension_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('gestor_user_id');
            $table->unsignedBigInteger('user_subsystem_account_id');
            $table->timestamps();

            $table->primary(
                ['gestor_user_id', 'user_subsystem_account_id'],
                'gestor_user_suspension_accounts_pk',
            );
            $table->foreign('gestor_user_id', 'gestor_user_suspension_accounts_user_fk')
                ->references('id')->on('gestor_users')->cascadeOnDelete();
            $table->foreign('user_subsystem_account_id', 'gestor_user_suspension_accounts_account_fk')
                ->references('id')->on('user_subsystem_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gestor_user_suspension_accounts');

        Schema::table('gestor_users', function (Blueprint $table) {
            $table->dropColumn(['suspendido_at', 'motivo_suspension_gestor']);
        });
    }
};
