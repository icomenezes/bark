<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SignedDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'certificate_id' => null,
            'verification_code' => (string) Str::uuid(),
            'title' => fake()->sentence(3),
            'sha256' => hash('sha256', fake()->uuid()),
            'signed_at' => now(),
        ];
    }
}
