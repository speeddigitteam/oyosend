<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Modules\CRM\Models\TimelineEntry;
use Illuminate\Database\Eloquent\Collection;

final class TimelineRecorder
{
    public function __construct(private readonly LinkedRecordVisibility $visibility) {}

    /**
     * @param  array{contact_id?: string|null, company_id?: string|null, lead_id?: string|null, deal_id?: string|null, activity_id?: string|null}  $links
     * @param  array<string, bool|int|string|null>  $metadata
     */
    public function record(string $event, string $summary, array $links, ?TenantMembership $actor = null, array $metadata = []): TimelineEntry
    {
        /** @var TimelineEntry $entry */
        $entry = TimelineEntry::query()->create([
            'event' => $event,
            'summary' => $summary,
            'contact_id' => $links['contact_id'] ?? null,
            'company_id' => $links['company_id'] ?? null,
            'lead_id' => $links['lead_id'] ?? null,
            'deal_id' => $links['deal_id'] ?? null,
            'activity_id' => $links['activity_id'] ?? null,
            'actor_id' => $actor?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);

        return $entry;
    }

    /** @return Collection<int, TimelineEntry> */
    public function forLead(string $leadId, int $limit = 100): Collection
    {
        return TimelineEntry::query()->where('lead_id', $leadId)->with('actor.user:id,name')->latest('occurred_at')->limit($limit)->get();
    }

    /** @return Collection<int, TimelineEntry> */
    public function forDeal(string $dealId, int $limit = 100): Collection
    {
        return TimelineEntry::query()->where('deal_id', $dealId)->with('actor.user:id,name')->latest('occurred_at')->limit($limit)->get();
    }

    /** @return Collection<int, TimelineEntry> */
    public function forContact(string $contactId, int $limit = 100, ?User $viewer = null): Collection
    {
        $query = TimelineEntry::query()->where('contact_id', $contactId);

        if ($viewer !== null) {
            $this->visibility->constrain($query, $viewer);
        }

        return $query->with('actor.user:id,name')->latest('occurred_at')->limit($limit)->get();
    }

    /** @return Collection<int, TimelineEntry> */
    public function forCompany(string $companyId, int $limit = 100, ?User $viewer = null): Collection
    {
        $query = TimelineEntry::query()->where('company_id', $companyId);

        if ($viewer !== null) {
            $this->visibility->constrain($query, $viewer);
        }

        return $query->with('actor.user:id,name')->latest('occurred_at')->limit($limit)->get();
    }
}
