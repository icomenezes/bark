<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** Atribui um plano com limites folgados — usado em testes que não testam a feature de limite. */
    public function withPlan(int $maxPdfs = 1000, int $maxEnvelopes = 1000): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => Plan::factory()->create([
                'max_pdfs_per_month' => $maxPdfs,
                'max_envelopes_per_month' => $maxEnvelopes,
            ]),
        ]);
    }
}
