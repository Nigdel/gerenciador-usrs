<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // El registro público está cerrado, así que sin esto una base recién
        // desplegada se queda sin ningún admin. El comando users:create-admin
        // es la vía para crearlo con contraseña propia; este usuario es solo
        // para desarrollo local.
        if (User::query()->where('role', 'admin')->exists()) {
            $this->command?->warn('Ya hay un administrador: no se crea otro.');

            return;
        }

        User::factory()->admin()->create([
            'name' => 'Test Admin',
            'email' => 'test@example.com',
        ]);
    }
}
