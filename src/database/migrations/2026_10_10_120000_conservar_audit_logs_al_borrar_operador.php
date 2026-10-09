<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `audit_logs.user_id` estaba en CASCADE, así que al borrar un operador
     * desaparecían también sus entradas: justo lo contrario de lo que sirve una
     * bitácora. El resto de tablas de auditoría del proyecto
     * (`account_state_logs`, `provisioning_operations`) ya usan SET NULL, y por
     * eso el observer congela el `actor_nombre` — para que la entrada siga
     * diciendo quién actuó aunque el usuario ya no exista.
     *
     * `audit_logs` no congela el nombre, así que SET NULL deja el hueco pero
     * conserva la acción, el payload y la IP: que es lo que se puede leer.
     */
    public function up(): void
    {
        // SQLite no admite añadir una FK a una tabla que ya existe: su sintaxis
        // es `ALTER TABLE ... ADD CONSTRAINT`, que no forma parte de SQL. La
        // migración se salta en las pruebas —que corren en :memory:— y se
        // aplica en MySQL, que es donde vive el dato.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement(
            'ALTER TABLE audit_logs
             ADD CONSTRAINT audit_logs_user_id_foreign
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement(
            'ALTER TABLE audit_logs
             ADD CONSTRAINT audit_logs_user_id_foreign
             FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE'
        );
    }
};
