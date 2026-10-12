<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Models\Segment;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\SegmentRules;
use Illuminate\Database\Eloquent\Builder;

/**
 * The members a campaign or a segment preview is about: subscribed members of the list, narrowed by the segment's
 * declarative rules. Consent and suppression are NOT decided here (that is the platform's `MarketingAudience`).
 */
final class AudienceBuilder
{
    /** @return Builder<Subscription> */
    public function members(string $listId, ?Segment $segment = null): Builder
    {
        $query = Subscription::query()
            ->whereHas('contact')
            ->where('list_id', $listId)
            ->where('status', SubscriptionStatus::Subscribed->value);

        if ($segment !== null) {
            SegmentRules::apply($query, $segment->rules, $segment->match);
        }

        return $query;
    }

    public function count(string $listId, ?Segment $segment = null): int
    {
        return $this->members($listId, $segment)->count();
    }
}
