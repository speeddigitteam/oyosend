<?php

namespace Database\Factories;

use App\Core\Identity\Models\Invitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Needs a `role_id` of the active tenant. The plain token is not recoverable
 * from a factory-made invitation; use `withToken()` when a test needs it.
 *
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'expires_at' => now()->addDays(7),
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => Invitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subDay()]);
    }
}
