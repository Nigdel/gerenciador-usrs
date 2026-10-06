<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    protected $signature = 'users:create-admin
                            {--name= : Nombre del administrador (por defecto "Administrador")}
                            {--email= : Correo del administrador; si falta, lo pregunta}
                            {--password= : Contraseña; si falta, la pregunta de forma oculta}';

    protected $description = 'Crea el primer usuario administrador del sistema';

    public function handle(): int
    {
        // El registro público está cerrado, así que el primer admin no se puede
        // crear desde la web: este comando es la vía de arranque.
        $existente = User::query()->where('role', 'admin')->first();

        if ($existente instanceof User) {
            $this->components->warn("Ya existe un administrador: {$existente->email}.");
            $this->line('  Para crear otro, usa el módulo de usuarios desde la web.');

            return self::FAILURE;
        }

        $email = $this->resolverEmail();
        $name = (string) ($this->option('name') ?: 'Administrador');
        $password = $this->resolverPassword();

        if ($email === null || $password === null) {
            return self::FAILURE;
        }

        $fallo = $this->validar($name, $email, $password);

        if ($fallo !== null) {
            $this->components->error($fallo);

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,   // el cast 'hashed' del modelo la guarda hasheada
            'role' => 'admin',
        ]);

        $this->components->info("Administrador creado: {$email}");
        $this->components->twoColumnDetail('Nombre', $name);
        $this->components->twoColumnDetail('Rol', 'admin');

        return self::SUCCESS;
    }

    private function resolverEmail(): ?string
    {
        $email = $this->option('email');

        if ($email !== null && $email !== '') {
            return (string) $email;
        }

        $email = $this->ask('Correo del administrador');

        return $email === null ? null : (string) $email;
    }

    /**
     * Sin --password la pregunta en modo oculto: escribirla en el comando la
     * dejaría escrita en el historial del shell.
     */
    private function resolverPassword(): ?string
    {
        $password = $this->option('password');

        if ($password !== null && $password !== '') {
            return (string) $password;
        }

        $password = $this->secret('Contraseña (mín. 8, 1 mayúscula, 1 número, 1 símbolo)');

        return $password === null ? null : (string) $password;
    }

    /**
     * Aplica las mismas reglas que el formulario de alta de usuarios, para que
     * un admin creado por consola no sea un usuario que la web rechazaría.
     *
     * Devuelve null si todo está bien. Ojo: errors()->first() devuelve ''
     * —string vacío, no null— cuando no hay fallos, así que hay que comprobar
     * con filled() y no con !== null.
     */
    private function validar(string $name, string $email, string $password): ?string
    {
        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ]
        );

        $fallo = $validator->errors()->first();

        if (filled($fallo)) {
            return $fallo;
        }

        // La app usa la regla propia del formulario de usuarios, no Password::defaults().
        if (preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).+$/', $password) !== 1) {
            return 'La contraseña necesita al menos 8 caracteres, 1 minúscula, 1 mayúscula, 1 número y 1 símbolo.';
        }

        return null;
    }
}
