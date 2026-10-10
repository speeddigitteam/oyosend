<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\AppStore\AppStoreManager;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Models\Invoice;
use Carbon\CarbonInterface;

/** Routes a verified invoice to the one domain service allowed to fulfil it. */
final class InvoiceSettlement
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly AppStoreManager $apps,
    ) {}

    public function settle(Invoice $invoice, ?CarbonInterface $paidAt = null): bool
    {
        return match ($invoice->kind) {
            InvoiceKind::AppPurchase, InvoiceKind::AppRenewal => $this->apps->settle($invoice, $paidAt),
            default => $this->subscriptions->settle($invoice, $paidAt),
        };
    }
}
