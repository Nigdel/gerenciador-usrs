<?php

namespace Database\Factories;

use App\Models\Subsystem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subsystem>
 */
class SubsystemFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Subsystem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->word(),
            'slug' => fake()->unique()->word(),
            'descripcion' => null,
            'api_url' => fake()->url(),
            'api_config' => [],
            'external_subsystem_id' => null,
            'activo' => rand(0, 1),
            'es_proveedor_identidad' => rand(0, 1),
            'last_connection_test_at' => null,
            'last_connection_test_success' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Indicate that the subsystem is active.
     */
    public function activo(): static
    {
        return $this->state(['activo' => 1]);
    }

    /**
     * Indicate that the subsystem is inactive.
     */
    public function inactivo(): static
    {
        return $this->state(['activo' => 0]);
    }

    /**
     * Indicate that the subsystem is a provider of identity.
     */
    public function proveedorIdentidad(): static
    {
        return $this->state(['es_proveedor_identidad' => 1]);
    }

    /**
     * Indicate that the subsystem is NOT a provider of identity.
     */
    public function noProveedorIdentidad(): static
    {
        return $this->state(['es_proveedor_identidad' => 0]);
    }
}
