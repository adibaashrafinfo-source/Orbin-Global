<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '01'.fake()->numerify('#########'),
            'password_hash' => static::$password ??= Hash::make('password'),
            'role' => 'cashier',
            'language' => 'en',
            'is_active' => true,
            'must_change_password' => false,
        ];
    }

    public function role(string $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function withPin(string $pin = '1234'): static
    {
        return $this->state(fn () => ['override_pin_hash' => Hash::make($pin)]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
