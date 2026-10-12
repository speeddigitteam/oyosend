<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Events;

use App\Modules\EmailMarketing\Models\Subscription;

/** A contact became a subscribed member of a list, with consent evidence recorded on the membership. */
final class SubscriberSubscribed extends EmailMarketingEvent
{
    public static function from(Subscription $subscription): self
    {
        return new self([
            'subscription_id' => $subscription->id,
            'list_id' => $subscription->list_id,
            'contact_id' => $subscription->contact_id,
            'source' => $subscription->source,
        ]);
    }

    public static function name(): string
    {
        return 'email_marketing.subscriber.subscribed';
    }
}
