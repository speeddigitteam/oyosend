<?php

declare(strict_types=1);

namespace App\Core\Billing\Events;

use App\Core\Billing\Models\Subscription;
use App\Core\Events\TenantDomainEvent;

/**
 * The workspace's subscription changed: its plan, its status or its period.
 */
final class SubscriptionUpdated extends TenantDomainEvent
{
    public readonly string $subscriptionId;

    public readonly string $planId;

    public readonly string $status;

    public readonly string $interval;

    public function __construct(Subscription $subscription)
    {
        parent::__construct();

        $this->subscriptionId = $subscription->id;
        $this->planId = $subscription->plan_id;
        $this->status = $subscription->status->value;
        $this->interval = $subscription->interval->value;
    }

    public static function name(): string
    {
        return 'core.subscription.updated';
    }

    public function payload(): array
    {
        return [
            'subscription_id' => $this->subscriptionId,
            'plan_id' => $this->planId,
            'status' => $this->status,
            'interval' => $this->interval,
        ];
    }
}
