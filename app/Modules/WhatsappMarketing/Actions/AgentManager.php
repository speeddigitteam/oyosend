<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Billing\UsageLimits;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Models\Agent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Members enrolled to work the inbox. Active agents count against the plan's `whatsapp_marketing.agents` limit. */
final class AgentManager
{
    /** Gauge plan limit: agents enrolled and active. */
    public const string LIMIT = 'whatsapp_marketing.agents';

    public function __construct(private readonly UsageLimits $limits) {}

    /** @param array{is_available?: bool, max_open_conversations?: int|null} $attributes */
    public function enrol(string $membershipId, array $attributes = []): Agent
    {
        return DB::transaction(function () use ($membershipId, $attributes): Agent {
            $membership = TenantMembership::query()->where('status', MembershipStatus::Active)->find($membershipId)
                ?? throw ValidationException::withMessages(['membership_id' => __('Choose an active member of this workspace.')]);

            $agent = Agent::query()->where('membership_id', $membership->id)->lockForUpdate()->first();

            if ($agent === null) {
                $this->limits->ensure(self::LIMIT, 1, 'membership_id');

                return Agent::query()->create([
                    'membership_id' => $membership->id,
                    'is_active' => true,
                    'is_available' => $attributes['is_available'] ?? true,
                    'max_open_conversations' => $attributes['max_open_conversations'] ?? null,
                ]);
            }

            if (! $agent->is_active) {
                $this->limits->ensure(self::LIMIT, 1, 'membership_id');
                $agent->is_active = true;
            }

            $agent->fill(array_intersect_key($attributes, ['is_available' => 1, 'max_open_conversations' => 1]))->save();

            return $agent;
        });
    }

    /** @param array{is_active?: bool, is_available?: bool, max_open_conversations?: int|null} $attributes */
    public function update(Agent $agent, array $attributes): Agent
    {
        return DB::transaction(function () use ($agent, $attributes): Agent {
            $locked = Agent::query()->whereKey($agent->id)->lockForUpdate()->firstOrFail();

            if (($attributes['is_active'] ?? $locked->is_active) && ! $locked->is_active) {
                $this->limits->ensure(self::LIMIT, 1, 'is_active');
            }

            $locked->fill($attributes)->save();

            return $locked;
        });
    }

    /** The membership of an active agent whose membership is active, or null. */
    public function activeMembership(string $membershipId): ?TenantMembership
    {
        $agent = Agent::query()->where('membership_id', $membershipId)->where('is_active', true)->first();

        if ($agent === null) {
            return null;
        }

        return TenantMembership::query()->where('status', MembershipStatus::Active)->find($membershipId);
    }
}
