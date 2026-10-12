<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Models\Conversation;

/** Picks the available agent with the fewest open conversations, within each agent's own cap. */
final class AutoAssigner
{
    public function pick(): ?TenantMembership
    {
        $agents = Agent::query()
            ->where('is_active', true)
            ->where('is_available', true)
            ->with('membership')
            ->get()
            ->filter(static fn (Agent $agent): bool => $agent->membership->status === MembershipStatus::Active);

        if ($agents->isEmpty()) {
            return null;
        }

        /** @var array<string, int> $open */
        $open = Conversation::query()
            ->where('status', ConversationStatus::Open->value)
            ->whereIn('owner_id', $agents->pluck('membership_id')->all())
            ->toBase()->selectRaw('owner_id, count(*) as total')
            ->groupBy('owner_id')
            ->pluck('total', 'owner_id')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        $best = null;
        $bestCount = PHP_INT_MAX;

        foreach ($agents->sortBy('id') as $agent) {
            $count = $open[$agent->membership_id] ?? 0;

            if ($agent->max_open_conversations !== null && $count >= $agent->max_open_conversations) {
                continue;
            }

            if ($count < $bestCount) {
                $best = $agent;
                $bestCount = $count;
            }
        }

        return $best?->membership;
    }
}
