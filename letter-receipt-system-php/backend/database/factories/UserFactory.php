<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => Str::lower($this->faker->unique()->userName()),
            'password' => static::$password ??= Hash::make('password123'),
            'display_name' => $this->faker->name(),
            'role' => $this->faker->randomElement(['ADMIN', 'RECEPTIONIST']),
            'active' => true,
        ];
    }

    /**
     * Indicate that the user is an admin.
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'ADMIN',
        ]);
    }

    /**
     * Indicate that the user is a receptionist.
     */
    public function receptionist(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'RECEPTIONIST',
        ]);
    }

    /**
     * Indicate that the user is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}