<?php

namespace Database\Factories;

use App\Platform\Enums\PlatformRole;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformUser>
 */
class PlatformUserFactory extends Factory
{
    protected $model = PlatformUser::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => PlatformRole::SuperAdmin,
            'remember_token' => Str::random(10),
        ];
    }

    public function support(): static
    {
        return $this->state(fn (): array => ['role' => PlatformRole::SupportAdmin]);
    }
}
