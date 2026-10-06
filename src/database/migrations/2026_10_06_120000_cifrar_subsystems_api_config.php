<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El cast encrypted:array guarda base64, no JSON. Mientras la columna
        // sea JSON, MySQL rechaza cada escritura por dato inválido.
        Schema::table('subsystems', function (Blueprint $table): void {
            $table->text('api_config')->nullable()->change();
        });

        $this->recifrar();
    }

    public function down(): void
    {
        // Se deshace el cifrado antes de volver a JSON: MySQL tampoco acepta
        // base64 en una columna JSON.
        DB::table('subsystems')
            ->select(['id', 'api_config'])
            ->whereNotNull('api_config')
            ->orderBy('id')
            ->chunkById(50, function ($subsistemas): void {
                foreach ($subsistemas as $subsistema) {
                    $plano = json_decode(Crypt::decryptString((string) $subsistema->api_config), true);

                    if (! is_array($plano)) {
                        $this->abortar($subsistema->id, 'no se pudo decodificar');
                    }

                    DB::table('subsystems')->where('id', $subsistema->id)->update([
                        'api_config' => json_encode($plano),
                    ]);
                }
            });

        Schema::table('subsystems', function (Blueprint $table): void {
            $table->json('api_config')->nullable()->change();
        });
    }

    /**
     * Re-cifra la configuración que hubiera guardada en claro.
     *
     * Se lee con DB::table y no con el modelo a propósito: mientras la migración
     * corre, el modelo todavía usa el cast 'array', y aquí lo que interesa es
     * exactamente el texto tal como está en la base.
     */
    private function recifrar(): void
    {
        DB::table('subsystems')
            ->select(['id', 'api_config'])
            ->whereNotNull('api_config')
            ->orderBy('id')
            ->chunkById(50, function ($subsistemas): void {
                foreach ($subsistemas as $subsistema) {
                    $cifrado = (string) $subsistema->api_config;

                    // Una fila ya cifrada se deja como está: re-cifrarla exigiría
                    // la clave del cifrado anterior.
                    if ($this->pareceCifrada($cifrado)) {
                        continue;
                    }

                    $plano = json_decode($cifrado, true);

                    if (! is_array($plano)) {
                        $this->abortar($subsistema->id, 'no contenía JSON válido');
                    }

                    DB::table('subsystems')->where('id', $subsistema->id)->update([
                        'api_config' => Crypt::encryptString(json_encode($plano)),
                    ]);
                }
            });
    }

    /**
     * Un texto cifrado por Laravel no es JSON válido: lo detecta el propio
     * json_decode, así que solo hace falta comprobar que no lo sea.
     */
    private function pareceCifrada(string $valor): bool
    {
        return json_decode($valor, true) === null && json_last_error() !== JSON_ERROR_NONE;
    }

    private function abortar(int $id, string $motivo): never
    {
        throw new RuntimeException(
            "No se pudo cifrar subsystems.api_config (id {$id}): {$motivo}. "
            .'Corrige la fila a mano o restaura la tabla antes de migrar.'
        );
    }
};
