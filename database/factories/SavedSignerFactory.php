<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavedSignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'channel' => 'email',
            'email' => fake()->unique()->safeEmail(),
            'whatsapp' => null,
            'auth_method' => 'link',
        ];
    }
}
