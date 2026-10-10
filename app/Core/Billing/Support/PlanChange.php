<?php

declare(strict_types=1);

namespace App\Core\Billing\Support;

use App\Core\Billing\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * What became of a request to change plan.
 */
final readonly class PlanChange
{
    public const string APPLIED = 'applied';

    public const string SCHEDULED = 'scheduled';

    public const string AWAITING_PAYMENT = 'awaiting_payment';

    private function __construct(
        public string $outcome,
        public ?Invoice $invoice = null,
        public ?Carbon $effectiveAt = null,
    ) {}

    /** The workspace is on the new plan now. */
    public static function applied(): self
    {
        return new self(self::APPLIED);
    }

    /** The new plan starts when the period that was paid for ends. */
    public static function scheduled(Carbon $effectiveAt): self
    {
        return new self(self::SCHEDULED, effectiveAt: $effectiveAt);
    }

    /** The new plan starts when this invoice is paid. */
    public static function awaitingPayment(Invoice $invoice): self
    {
        return new self(self::AWAITING_PAYMENT, $invoice);
    }
}
