<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Billing\Models\Subscription;
use App\Core\Tenancy\TenantContext;

/**
 * The subscription of the active workspace, read once per request. Shared by
 * everything that asks on every page: the paywall, the billing notice in the
 * layout, usage limits.
 */
final class CurrentSubscription
{
    /** @var array<string, Subscription|null> tenant id => subscription */
    private array $loaded = [];

    public function __construct(private readonly TenantContext $context) {}

    public function get(): ?Subscription
    {
        $tenantId = $this->context->requireId();

        if (! array_key_exists($tenantId, $this->loaded)) {
            $this->loaded[$tenantId] = Subscription::query()->with(['plan', 'pendingPlan'])->first();
        }

        return $this->loaded[$tenantId];
    }

    /**
     * Called by SubscriptionManager after every change.
     */
    public function forget(): void
    {
        $this->loaded = [];
    }
}
