<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What time does to the subscription of the active workspace. Run for every
 * tenant by `billing:run` (hourly); running it again changes nothing.
 *
 *  - issues the next invoice a few days before a trial or a paid period ends;
 *  - when that moment passes unpaid, the subscription is past due and the
 *    grace period starts;
 *  - when the grace period ends unpaid, the subscription expires and the
 *    workspace is locked down to billing;
 *  - a cancellation or a scheduled move to another plan takes effect at the
 *    end of the period.
 *
 * Nothing is charged from here: the platform holds no payment details. An
 * invoice is paid by the workspace through a gateway, or recorded by an
 * operator.
 */
final class BillingCycle
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly InvoiceIssuer $invoices,
        private readonly PlatformSettings $settings,
    ) {}

    public function run(): void
    {
        DB::transaction(function (): void {
            $subscription = Subscription::query()->with(['plan', 'pendingPlan'])->lockForUpdate()->first();

            if ($subscription === null || $subscription->isLocked()) {
                return;
            }

            $now = Carbon::now();

            $this->withdrawStaleChange($now);

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $this->trialing($subscription, $now);
            } elseif ($subscription->status === SubscriptionStatus::Active) {
                $this->active($subscription, $now);
            }

            // Not `else`: a workspace whose cycle did not run for a while can
            // go past due and run out of grace in the same pass.
            if ($subscription->status === SubscriptionStatus::PastDue) {
                $this->pastDue($subscription, $now);
            }
        });
    }

    private function trialing(Subscription $subscription, CarbonInterface $now): void
    {
        $end = $subscription->trial_ends_at ?? $now;

        if ($subscription->cancel_at_period_end) {
            if ($end->lessThanOrEqualTo($now)) {
                $this->subscriptions->end($subscription, SubscriptionStatus::Canceled);
            }

            return;
        }

        if ($this->dueForInvoice($end, $now)) {
            $this->invoices->issue(
                $subscription,
                InvoiceKind::Subscription,
                $subscription->plan,
                $subscription->interval,
                $subscription->amount,
                $end,
                $end,
            );
        }

        if ($end->lessThanOrEqualTo($now)) {
            $this->subscriptions->markPastDue($subscription, $end);
        }
    }

    private function active(Subscription $subscription, CarbonInterface $now): void
    {
        $end = $subscription->current_period_end;

        // A free plan has no period: nothing ever falls due.
        if ($end === null) {
            return;
        }

        if ($subscription->cancel_at_period_end) {
            if ($end->lessThanOrEqualTo($now)) {
                $this->subscriptions->end($subscription, SubscriptionStatus::Canceled);
            }

            return;
        }

        [$plan, $interval, $amount] = $this->nextPeriod($subscription);

        if ($amount === 0) {
            if ($end->lessThanOrEqualTo($now)) {
                $this->subscriptions->switchToFree($subscription, $plan, $interval);
            }

            return;
        }

        if ($this->dueForInvoice($end, $now)) {
            $this->invoices->issue($subscription, InvoiceKind::Renewal, $plan, $interval, $amount, $end, $end);
        }

        if ($end->lessThanOrEqualTo($now)) {
            $this->subscriptions->markPastDue($subscription, $end);
        }
    }

    private function pastDue(Subscription $subscription, CarbonInterface $now): void
    {
        if ($subscription->grace_ends_at === null || $subscription->grace_ends_at->lessThanOrEqualTo($now)) {
            // The invoice stays open: paying it brings the workspace back.
            $this->subscriptions->end($subscription, SubscriptionStatus::Expired);

            return;
        }

        // Past due always has something to pay, also after an invoice was withdrawn.
        if (! $this->invoices->hasOpen()) {
            [$plan, $interval, $amount] = $this->nextPeriod($subscription);
            $boundary = $subscription->current_period_end ?? $now;

            if ($amount > 0) {
                $this->invoices->issue($subscription, InvoiceKind::Renewal, $plan, $interval, $amount, $boundary, $boundary);
            }
        }
    }

    /**
     * What the next period is billed for: a scheduled change of plan if there
     * is one, otherwise the same plan at the price it was taken at.
     *
     * @return array{0: Plan, 1: BillingInterval, 2: int}
     */
    private function nextPeriod(Subscription $subscription): array
    {
        if ($subscription->pendingPlan === null) {
            return [$subscription->plan, $subscription->interval, $subscription->amount];
        }

        $interval = $subscription->pending_interval ?? $subscription->interval;

        return [$subscription->pendingPlan, $interval, $subscription->pendingPlan->amountFor($interval) ?? $subscription->amount];
    }

    /**
     * Whether it is time to ask for the period that starts at `$boundary`.
     */
    private function dueForInvoice(CarbonInterface $boundary, CarbonInterface $now): bool
    {
        return Carbon::instance($boundary)->subDays($this->settings->invoiceLeadDays())->lessThanOrEqualTo($now)
            && ! $this->invoices->hasOpen();
    }

    /**
     * An unpaid invoice for a change of plan carries a credit worked out on
     * the day it was issued; past its due date it is withdrawn.
     */
    private function withdrawStaleChange(CarbonInterface $now): void
    {
        $open = $this->invoices->open();

        if ($open !== null && $open->kind === InvoiceKind::Change && $open->due_at !== null && $open->due_at->lessThanOrEqualTo($now)) {
            $this->invoices->void($open);
        }
    }
}
