<?php

namespace Database\Factories;

use App\Models\Envelope;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnvelopeSignerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'auth_method' => 'link',
            'channel' => 'email',
            'sign_position' => 1,
            'status' => 'pending',
        ];
    }
}
