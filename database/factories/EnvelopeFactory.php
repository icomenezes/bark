<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EnvelopeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'verification_code' => (string) Str::uuid(),
            'original_pdf_path' => 'envelopes/1/original.pdf',
            'sha256_original' => hash('sha256', fake()->uuid()),
            'signing_order' => 'parallel',
            'status' => 'draft',
        ];
    }
}
