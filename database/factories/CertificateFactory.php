<?php

namespace Database\Factories;

use App\Models\Certificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'description' => fake()->company(),
            'reference' => (string) fake()->numberBetween(1, 999),
            'pfx_path' => 'certificates/1/certificate.pfx',
            'password' => 'secret',
            'expires_at' => now()->addYear(),
        ];
    }
}
