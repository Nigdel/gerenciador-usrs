<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3.2 — operaciones y su detalle por cuenta.
 *
 * Antes de la cola, el resultado de una acción existía solo en el flash de la
 * sesión: si el operador recargaba, se perdía, y si la petición moría a mitad
 * no quedaba rastro de lo que se había tocado. Aquí se guarda la operación
 * completa, y una fila por cuenta con su estado (pendiente / ok / error).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provisioning_operations', function (Blueprint $table) {
            $table->id();

            // El identificador que viaja en la respuesta de la API y en la URL
            // del panel. Un id autoincremental se adivina al probarlo; el uuid
            // no, y por eso es unique.
            $table->uuid('uuid')->unique();

            $table->unsignedBigInteger('gestor_user_id')->nullable();
            $table->string('tipo');                // OperationType
            $table->string('estado')->default('pendiente'); // OperationStatus

            // Datos de la operación: motivo de la baja, motivo y fechas de la
            // suspensión, slugs elegidos...
            //
            // TEXT y no JSON a propósito: se guarda cifrado (encrypted:array).
            // En el alta el payload lleva password_general, y dejarlo en claro
            // en la base convertiría una tabla de trazabilidad en un almacén
            // de contraseñas. Mismo criterio que subsystems.api_config.
            $table->text('payload')->nullable();

            $table->unsignedBigInteger('actor_id')->nullable();
            // Congelado al escribir: si el operador se borra, la operación no
            // debe quedarse sin autor legible.
            $table->string('actor_nombre')->nullable();
            $table->string('origen')->default('web'); // web | api | scheduler

            // Contadores desnormalizados para poder pintar el panel sin contar
            // filas en cada consulta.
            $table->unsignedInteger('pendientes')->default(0);
            $table->unsignedInteger('exitos')->default(0);
            $table->unsignedInteger('errores')->default(0);

            $table->timestamp('iniciada_at')->nullable();
            $table->timestamp('terminada_at')->nullable();

            $table->timestamps();

            // SET NULL y no CASCADE, igual que en account_state_logs: borrar
            // al usuario no debe borrar la traza de lo que se intentó hacer con
            // sus cuentas.
            $table->foreign('gestor_user_id')
                ->references('id')->on('gestor_users')->nullOnDelete()
                ->name('provisioning_operations_usuario_fk');
            $table->foreign('actor_id')
                ->references('id')->on('users')->nullOnDelete()
                ->name('provisioning_operations_actor_fk');

            $table->index(['gestor_user_id', 'created_at'], 'provisioning_operations_usuario_fecha_idx');
            $table->index(['estado', 'created_at'], 'provisioning_operations_estado_fecha_idx');
        });

        Schema::create('provisioning_operation_accounts', function (Blueprint $table) {
            $table->id();

            // CASCADE aquí sí: estas filas son el trabajo pendiente de esa
            // operación. Sin operación no tienen sentido.
            $table->unsignedBigInteger('provisioning_operation_id');

            // NULL en el alta: la cuenta todavía no existe cuando se encola la
            // operación (es el job el que hace el updateOrCreate). El job
            // rellena este campo al terminar.
            $table->unsignedBigInteger('user_subsystem_account_id')->nullable();
            $table->unsignedBigInteger('subsystem_id')->nullable();

            // Slug congelado en el momento de encolar: es lo único que se puede
            // mostrar mientras la cuenta aún no existe, y sobrevive a que la
            // cuenta se borre.
            $table->string('subsistema');

            $table->string('estado')->default('pendiente'); // OperationAccountStatus
            $table->string('mensaje')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);

            // Datos por cuenta: motivo y fechas de la suspensión, datos de
            // sincronización...
            $table->json('payload')->nullable();

            $table->timestamps();

            // Los nombres de las claves se dan explícitos porque el nombre que
            // genera Laravel sería
            // 'provisioning_operation_accounts_provisioning_operation_id_foreign',
            // que son 69 caracteres y MySQL no admite más de 64 en un
            // identificador. Sin nombrarlas, la migración falla en MySQL.
            $table->foreign('provisioning_operation_id')
                ->references('id')->on('provisioning_operations')
                ->cascadeOnDelete()
                ->name('provisioning_op_accounts_operacion_fk');
            $table->foreign('user_subsystem_account_id')
                ->references('id')->on('user_subsystem_accounts')
                ->nullOnDelete()
                ->name('provisioning_op_accounts_cuenta_fk');
            $table->foreign('subsystem_id')
                ->references('id')->on('subsystems')
                ->nullOnDelete()
                ->name('provisioning_op_accounts_subsistema_fk');

            $table->index(['provisioning_operation_id', 'estado'], 'provisioning_op_accounts_operacion_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provisioning_operation_accounts');
        Schema::dropIfExists('provisioning_operations');
    }
};
