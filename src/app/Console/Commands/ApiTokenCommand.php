<?php

namespace App\Console\Commands;

use App\Enums\ApiAbility;
use App\Models\User;
use Illuminate\Console\Command;

class ApiTokenCommand extends Command
{
    protected $signature = 'api:token
                            {integracion : Nombre de la integración, p. ej. chatwoot}
                            {--abilities=* : Abilities separadas por coma (ver api:token --help)}
                            {--admin : Emite el token para el usuario admin del sistema}
                            {--revoke : Revoca el token anterior de esa integración en vez de emitir uno nuevo}';

    protected $description = 'Emite o revoca un token de API para una integración';

    public function handle(): int
    {
        $integracion = (string) $this->argument('integracion');

        if ($this->option('revoke')) {
            return $this->revocar($integracion);
        }

        return $this->emitir($integracion);
    }

    private function emitir(string $integracion): int
    {
        $abilities = $this->parsearAbilities();

        if ($abilities === null) {
            return self::FAILURE;
        }

        $user = $this->option('admin')
            ? $this->resolverAdmin()
            : $this->resolverUsuario();

        if (! $user instanceof User) {
            return self::FAILURE;
        }

        // Un token anterior de la misma integración deja de servir.
        $user->tokens()->where('name', $integracion)->delete();

        $token = $user->createToken($integracion, $abilities);

        $owner = $user->email ?? $user->name;
        $this->components->info("Token emitido para «{$integracion}» (owner: {$owner}).");
        $this->components->twoColumnDetail('Abilities', implode(', ', $abilities));
        $this->newLine();
        $this->line('  <fg=green>'.$token->plainTextToken.'</>');
        $this->components->warn('Guárdalo ahora: no se vuelve a mostrar.');

        return self::SUCCESS;
    }

    private function revocar(string $integracion): int
    {
        $user = $this->option('admin') ? $this->resolverAdmin() : null;

        $query = $user instanceof User
            ? $user->tokens()->where('name', $integracion)
            : User::query()
                ->whereHas('tokens', fn ($q) => $q->where('name', $integracion));

        $cantidad = (clone $query)->count();

        if ($cantidad === 0) {
            $this->components->warn("No hay ningún token de «{$integracion}».");

            return self::FAILURE;
        }

        $query->delete();

        $this->components->info("Se revocaron {$cantidad} token(s) de «{$integracion}».");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>|null Null si las abilities no son válidas.
     */
    private function parsearAbilities(): ?array
    {
        $raw = $this->option('abilities');

        if ($raw === []) {
            $this->components->error('Hay que indicar al menos una ability con --abilities.');
            $this->line('  Disponibles: '.implode(', ', ApiAbility::values()));

            return null;
        }

        $abilities = collect($raw)
            ->flatMap(fn (string $valor) => explode(',', $valor))
            ->map(fn (string $valor) => trim($valor))
            ->filter()
            ->unique()
            ->values();

        $invalidas = $abilities->reject(
            fn (string $valor) => in_array($valor, ApiAbility::values(), true)
        );

        if ($invalidas->isNotEmpty()) {
            $this->components->error('Abilities no reconocidas: '.$invalidas->implode(', '));
            $this->line('  Disponibles: '.implode(', ', ApiAbility::values()));

            return null;
        }

        return $abilities->all();
    }

    private function resolverAdmin(): ?User
    {
        $admin = User::query()->where('role', 'admin')->first();

        if (! $admin instanceof User) {
            $this->components->error('No hay ningún usuario con role=admin.');

            return null;
        }

        return $admin;
    }

    private function resolverUsuario(): ?User
    {
        $email = $this->ask('Email del usuario dueño del token');

        if ($email === null) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error('No existe un usuario con ese email.');

            return null;
        }

        return $user;
    }
}
