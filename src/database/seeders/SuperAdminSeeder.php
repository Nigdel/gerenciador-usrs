<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('SUPERADMIN_EMAIL', 'admin@ejemplo.com')],
            [
                'name' => 'Super Admin',
                'password' => env('SUPERADMIN_PASSWORD', 'CambiaEstaClave123'), // el cast 'hashed' la encripta
                'role' => 'admin',
                'externo' => false,
            ]
        );
    }
}