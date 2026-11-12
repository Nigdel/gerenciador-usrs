<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Termos de responsabilidad por medio.
 *
 * Un termo es una constancia documental: no se edita ni se borra. Por eso la
 * tabla guarda el snapshot completo (datos del usuario, del medio y de la
 * empresa) junto al PDF generado y al firmado. Si mañana GLPI cambia el nombre
 * del equipo o el usuario cambia de empresa, el termo sigue diciendo lo que
 * decía el día que se generó.
 *
 * El vínculo con el medio es (item_type, item_id), la misma pareja con la que
 * GLPI identifica un Computer, Phone, Monitor, etc. No hay FK al inventario de
 * GLPI porque vive en otra base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('gestor_user_id')
                ->constrained('gestor_users')
                ->cascadeOnDelete();

            // La cuenta de GLPI que declaraba el medio al generar el termo.
            // Puede desaparecer sin llevarse el termo: es un dato de contexto.
            $table->foreignId('user_subsystem_account_id')
                ->nullable()
                ->constrained('user_subsystem_accounts')
                ->nullOnDelete();

            $table->string('item_type', 50);
            $table->unsignedBigInteger('item_id');
            $table->string('item_name')->nullable();
            $table->string('item_serial')->nullable();

            $table->string('tipo', 20)->default('entrega');
            $table->string('estado', 20)->default('generado');

            // Datos congelados del termo: empresa, usuario y medio.
            $table->json('datos');

            $table->string('pdf_generado')->nullable();
            $table->string('pdf_firmado')->nullable();

            $table->timestamp('generado_at')->nullable();
            $table->timestamp('enviado_at')->nullable();
            $table->timestamp('firmado_at')->nullable();
            $table->timestamp('anulado_at')->nullable();
            $table->text('motivo_anulacion')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['gestor_user_id', 'item_type', 'item_id'], 'termos_medio_index');
            $table->index(['gestor_user_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termos');
    }
};
