<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'cpf')) {
                $table->string('cpf')->nullable()->unique();
            }

            if (! Schema::hasColumn('users', 'telefone_pessoal')) {
                $table->string('telefone_pessoal')->nullable();
            }

            if (! Schema::hasColumn('users', 'telefone_servico')) {
                $table->string('telefone_servico')->nullable();
            }

            if (! Schema::hasColumn('users', 'empresa')) {
                $table->string('empresa')->nullable();
            }

            if (! Schema::hasColumn('users', 'cargo')) {
                $table->string('cargo')->nullable();
            }

            if (! Schema::hasColumn('users', 'externo')) {
                $table->boolean('externo')->default(false);
            }

            if (! Schema::hasColumn('users', 'encarregado_id')) {
                $table->foreignId('encarregado_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'encarregado_id')) {
                $table->dropForeign(['encarregado_id']);
                $table->dropColumn('encarregado_id');
            }

            foreach (['externo', 'cargo', 'empresa', 'telefone_servico', 'telefone_pessoal', 'cpf'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
