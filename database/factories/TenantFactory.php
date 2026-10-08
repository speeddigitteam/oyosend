<?php

namespace Database\Factories;

use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare tenant row. Tests and seeders that need a working workspace (domain,
 * system roles, owner) should use App\Core\Tenancy\TenantProvisioner instead.
 *
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'status' => TenantStatus::Active,
            'locale' => 'en',
            'timezone' => 'Asia/Dhaka',
            'currency' => 'BDT',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => TenantStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
