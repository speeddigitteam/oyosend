<?php

declare(strict_types=1);

namespace App\Core\Billing\Support;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Settings\PlatformSettings;

/**
 * The shapes billing records take on a page. Shared by the workspace's
 * billing pages and the Super Admin panel, so both read the same fields.
 */
final class BillingPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function plan(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'key' => $plan->key,
            'name' => $plan->name,
            'description' => $plan->description,
            'currency' => $plan->currency,
            'monthlyAmount' => $plan->monthly_amount,
            'yearlyAmount' => $plan->yearly_amount,
            'trialDays' => $plan->trial_days,
            'modules' => array_values($plan->modules),
            'limits' => (object) $plan->limits,
            'isFree' => $plan->isFree(),
            'isPublic' => $plan->is_public,
            'isActive' => $plan->is_active,
            'sortOrder' => $plan->sort_order,
        ];
    }

    /**
     * Requires the `plan` and `pendingPlan` relations.
     *
     * @return array<string, mixed>|null
     */
    public static function subscription(?Subscription $subscription): ?array
    {
        if ($subscription === null) {
            return null;
        }

        return [
            'status' => $subscription->status->value,
            'plan' => [
                'id' => $subscription->plan->id,
                'key' => $subscription->plan->key,
                'name' => $subscription->plan->name,
            ],
            'interval' => $subscription->interval->value,
            'amount' => $subscription->amount,
            'currency' => $subscription->currency,
            'isFree' => $subscription->isFree(),
            'locked' => $subscription->isLocked(),
            'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
            'periodStart' => $subscription->current_period_start?->toIso8601String(),
            'periodEnd' => $subscription->current_period_end?->toIso8601String(),
            'graceEndsAt' => $subscription->grace_ends_at?->toIso8601String(),
            'cancelAtPeriodEnd' => $subscription->cancel_at_period_end,
            'endedAt' => $subscription->ended_at?->toIso8601String(),
            // A cheaper plan waiting for the paid period to end.
            'pending' => $subscription->pendingPlan === null ? null : [
                'plan' => $subscription->pendingPlan->name,
                'interval' => ($subscription->pending_interval ?? BillingInterval::Monthly)->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function invoice(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'kind' => $invoice->kind->value,
            'status' => $invoice->status->value,
            'overdue' => $invoice->isOverdue(),
            'currency' => $invoice->currency,
            'subtotalAmount' => $invoice->subtotal_amount,
            'creditAmount' => $invoice->credit_amount,
            'totalAmount' => $invoice->total_amount,
            'interval' => $invoice->interval?->value,
            'periodStart' => $invoice->period_start?->toIso8601String(),
            'periodEnd' => $invoice->period_end?->toIso8601String(),
            'issuedAt' => $invoice->issued_at->toIso8601String(),
            'dueAt' => $invoice->due_at?->toIso8601String(),
            'paidAt' => $invoice->paid_at?->toIso8601String(),
            'voidedAt' => $invoice->voided_at?->toIso8601String(),
        ];
    }

    /**
     * An invoice with everything printed on it.
     *
     * @return array<string, mixed>
     */
    public static function invoiceDocument(Invoice $invoice): array
    {
        return [
            ...self::invoice($invoice),
            'lines' => array_values($invoice->lines),
            'billedTo' => $invoice->billed_to,
            'issuer' => app(PlatformSettings::class)->invoiceIssuer(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'reference' => $payment->reference,
            'gateway' => $payment->gateway,
            'method' => $payment->method,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'paidAt' => $payment->paid_at?->toIso8601String(),
            'failureReason' => $payment->failure_reason,
            'createdAt' => $payment->created_at->toIso8601String(),
        ];
    }
}
