<?php

namespace Database\Factories;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Created inside a tenant context, the membership belongs to the active tenant.
 *
 * @extends Factory<TenantMembership>
 */
class TenantMembershipFactory extends Factory
{
    protected $model = TenantMembership::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'is_owner' => false,
            'joined_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => MembershipStatus::Suspended]);
    }
}
