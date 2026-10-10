<?php

declare(strict_types=1);

namespace App\Core\Billing;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\InvoiceKind;
use App\Core\Billing\Enums\InvoiceStatus;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Events\InvoicePaid;
use App\Core\Billing\Events\SubscriptionUpdated;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\Models\Subscription;
use App\Core\Billing\Support\PlanChange;
use App\Core\Entitlements\PlanEntitlementChecker;
use App\Core\Settings\PlatformSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the subscription of the active workspace.
 *
 * The rules, in one place:
 *
 *  - A paid plan is only ever switched on by a paid invoice ({@see settle()}).
 *    Nothing here treats a request, a redirect or a promise as payment.
 *  - A free plan needs no invoice and has no period.
 *  - Moving to a more expensive plan inside a paid period starts a new period
 *    when its invoice is paid; what was left of the old one is credited.
 *  - Moving to a cheaper plan waits for the end of the paid period.
 *  - A new plan taken without a trial gets the grace period to pay its first
 *    invoice.
 *
 * Time-driven steps (issuing the next invoice, past due, expiry) belong to
 * {@see BillingCycle}.
 */
final class SubscriptionManager
{
    /**
     * Days an invoice for a change of plan can be paid. Its credit is worked
     * out on the day it is issued, so it is withdrawn rather than left to go
     * stale; the change can simply be asked for again.
     */
    public const int CHANGE_OFFER_DAYS = 3;

    public function __construct(
        private readonly InvoiceIssuer $invoices,
        private readonly PlatformSettings $settings,
        private readonly PlanEntitlementChecker $entitlements,
        private readonly CurrentSubscription $currentSubscription,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): ?Subscription
    {
        return Subscription::query()->with(['plan', 'pendingPlan'])->first();
    }

    /**
     * Put a workspace that has no subscription yet on a plan.
     *
     * @throws ValidationException
     */
    public function start(Plan $plan, BillingInterval $interval, bool $withTrial = true): Subscription
    {
        return DB::transaction(function () use ($plan, $interval, $withTrial): Subscription {
            if (Subscription::query()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['plan' => __('This workspace already has a subscription.')]);
            }

            $amount = $this->price($plan, $interval);
            $now = Carbon::now();

            $attributes = [
                'plan_id' => $plan->getKey(),
                'interval' => $interval,
                'currency' => $plan->currency,
                'amount' => $amount,
            ];

            if ($amount === 0) {
                $attributes += ['status' => SubscriptionStatus::Active, 'current_period_start' => $now];
            } elseif ($withTrial && $plan->trial_days > 0) {
                $attributes += ['status' => SubscriptionStatus::Trialing, 'trial_ends_at' => $now->copy()->addDays($plan->trial_days)];
            } else {
                $attributes += [
                    'status' => SubscriptionStatus::PastDue,
                    'current_period_end' => $now,
                    'grace_ends_at' => $now->copy()->addDays($this->settings->graceDays()),
                ];
            }

            /** @var Subscription $subscription */
            $subscription = Subscription::query()->create($attributes);

            if ($subscription->status === SubscriptionStatus::PastDue) {
                $this->invoices->issue($subscription, InvoiceKind::Subscription, $plan, $interval, $amount, $now, $now);
            }

            $this->announce($subscription, 'billing.subscription_started', [
                'plan' => $plan->key,
                'interval' => $interval->value,
                'status' => $subscription->status->value,
            ]);

            return $subscription;
        });
    }

    /**
     * Move the workspace to another plan or interval.
     *
     * @throws ValidationException
     */
    public function changePlan(Plan $plan, BillingInterval $interval): PlanChange
    {
        return DB::transaction(function () use ($plan, $interval): PlanChange {
            $subscription = Subscription::query()->lockForUpdate()->first();

            if ($subscription === null) {
                $this->start($plan, $interval, withTrial: false);

                return ($open = $this->invoices->open()) === null ? PlanChange::applied() : PlanChange::awaitingPayment($open);
            }

            $amount = $this->price($plan, $interval);
            $now = Carbon::now();

            $unchanged = $subscription->plan_id === $plan->getKey() && $subscription->interval === $interval;

            if ($unchanged && $subscription->grantsAccess() && $subscription->pending_plan_id === null && ! $subscription->cancel_at_period_end) {
                throw ValidationException::withMessages(['plan' => __('The workspace is already on this plan.')]);
            }

            // Whatever was asked for or scheduled before no longer applies.
            $this->invoices->voidOpen();

            $subscription->fill([
                'pending_plan_id' => null,
                'pending_interval' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => $subscription->isLocked() ? $subscription->canceled_at : null,
            ]);

            if ($amount === 0) {
                // A paid period that is still running is used up first.
                if ($subscription->isInPaidPeriod()) {
                    return $this->schedule($subscription, $plan, $interval);
                }

                $this->switchToFree($subscription, $plan, $interval);

                return PlanChange::applied();
            }

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $subscription->fill([
                    'plan_id' => $plan->getKey(),
                    'interval' => $interval,
                    'currency' => $plan->currency,
                    'amount' => $amount,
                ])->save();

                $this->announce($subscription, 'billing.plan_changed', ['plan' => $plan->key, 'interval' => $interval->value]);

                return PlanChange::applied();
            }

            if ($subscription->isInPaidPeriod()) {
                if ($unchanged) {
                    // Only a scheduled change or a cancellation was being undone.
                    $subscription->save();
                    $this->announce($subscription, 'billing.subscription_resumed');

                    return PlanChange::applied();
                }

                if (! $this->isUpgrade($subscription, $amount, $interval)) {
                    return $this->schedule($subscription, $plan, $interval);
                }

                $subscription->save();

                $credit = $plan->currency === $subscription->currency ? $this->unusedValue($subscription, $now) : 0;
                $invoice = $this->invoices->issue(
                    $subscription,
                    InvoiceKind::Change,
                    $plan,
                    $interval,
                    $amount,
                    $now,
                    $now->copy()->addDays(self::CHANGE_OFFER_DAYS),
                    $credit,
                );

                if ($invoice->total_amount === 0) {
                    $this->settle($invoice, $now);

                    return PlanChange::applied();
                }

                return PlanChange::awaitingPayment($invoice);
            }

            // Past due, ended, or on a free plan: the new plan starts when its
            // first invoice is paid. A past-due workspace owes the period that
            // already began; everyone else starts a fresh one with the payment.
            $pastDue = $subscription->status === SubscriptionStatus::PastDue;

            $subscription->save();

            if ($pastDue || $subscription->isLocked()) {
                return PlanChange::awaitingPayment($this->invoices->issue(
                    $subscription,
                    InvoiceKind::Subscription,
                    $plan,
                    $interval,
                    $amount,
                    $pastDue ? ($subscription->current_period_end ?? $now) : $now,
                    $now,
                ));
            }

            return PlanChange::awaitingPayment($this->invoices->issue(
                $subscription,
                InvoiceKind::Change,
                $plan,
                $interval,
                $amount,
                $now,
                $now->copy()->addDays(self::CHANGE_OFFER_DAYS),
            ));
        });
    }

    /**
     * Stop the subscription: at the end of what was paid for (or of the
     * trial), or at once when a payment is already overdue.
     *
     * @throws ValidationException
     */
    public function cancel(): Subscription
    {
        return DB::transaction(function (): Subscription {
            $subscription = $this->locked();

            if ($subscription->isLocked()) {
                throw ValidationException::withMessages(['subscription' => __('This subscription has already ended.')]);
            }

            if ($subscription->isFree()) {
                throw ValidationException::withMessages(['subscription' => __('A free plan has nothing to cancel.')]);
            }

            $this->invoices->voidOpen();

            $now = Carbon::now();

            if ($subscription->status === SubscriptionStatus::PastDue) {
                $subscription->fill([
                    'status' => SubscriptionStatus::Canceled,
                    'canceled_at' => $now,
                    'ended_at' => $now,
                    'grace_ends_at' => null,
                ]);
            } else {
                $subscription->fill(['cancel_at_period_end' => true, 'canceled_at' => $now]);
            }

            $subscription->fill(['pending_plan_id' => null, 'pending_interval' => null])->save();

            $this->announce($subscription, 'billing.subscription_canceled', ['status' => $subscription->status->value]);

            return $subscription;
        });
    }

    /**
     * Take back a cancellation that has not taken effect yet.
     *
     * @throws ValidationException
     */
    public function resume(): Subscription
    {
        return DB::transaction(function (): Subscription {
            $subscription = $this->locked();

            if (! $subscription->cancel_at_period_end || $subscription->isLocked()) {
                throw ValidationException::withMessages(['subscription' => __('This subscription is not set to end.')]);
            }

            $subscription->fill(['cancel_at_period_end' => false, 'canceled_at' => null])->save();

            $this->announce($subscription, 'billing.subscription_resumed');

            return $subscription;
        });
    }

    /**
     * Mark an invoice paid and give the workspace what it bought.
     *
     * The only way a paid plan is switched on. Callers must have verified the
     * payment (or be recording one an operator vouches for). Idempotent: an
     * invoice that is no longer open is left alone.
     *
     * @return bool Whether this call settled the invoice.
     */
    public function settle(Invoice $invoice, ?CarbonInterface $paidAt = null): bool
    {
        return DB::transaction(function () use ($invoice, $paidAt): bool {
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if (! $invoice->isOpen()) {
                return false;
            }

            $paidAt = Carbon::instance($paidAt ?? Carbon::now());

            $invoice->update(['status' => InvoiceStatus::Paid, 'paid_at' => $paidAt]);

            $this->audit->record('billing.invoice_paid', $invoice, [
                'total_amount' => $invoice->total_amount,
                'currency' => $invoice->currency,
            ]);

            InvoicePaid::dispatch($invoice);

            $subscription = Subscription::query()->lockForUpdate()->first();

            if ($subscription === null || $invoice->plan_id === null || $invoice->interval === null) {
                return true;
            }

            // The period the invoice was issued for - unless the workspace had
            // been locked meanwhile, or this is a change of plan: then the
            // period starts with the payment.
            $start = $subscription->isLocked() || $invoice->kind === InvoiceKind::Change || $invoice->period_start === null
                ? $paidAt
                : $invoice->period_start;

            $subscription->fill([
                'plan_id' => $invoice->plan_id,
                'interval' => $invoice->interval,
                'currency' => $invoice->currency,
                'amount' => $invoice->subtotal_amount,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => $start,
                'current_period_end' => $invoice->interval->addTo($start),
                'grace_ends_at' => null,
                'ended_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'pending_plan_id' => null,
                'pending_interval' => null,
            ])->save();

            $this->announce($subscription, 'billing.subscription_activated', [
                'invoice' => $invoice->number,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ]);

            return true;
        });
    }

    /**
     * Put the workspace on a plan at once, without an invoice. For the Super
     * Admin panel: negotiated deals, complimentary access, corrections.
     *
     * @param  CarbonInterface|null  $periodEnd  When a paid plan given this way runs out; one interval from now by default.
     */
    public function grant(Plan $plan, BillingInterval $interval, ?CarbonInterface $periodEnd = null): Subscription
    {
        return DB::transaction(function () use ($plan, $interval, $periodEnd): Subscription {
            $amount = $this->price($plan, $interval);
            $now = Carbon::now();

            $subscription = Subscription::query()->lockForUpdate()->first() ?? new Subscription;

            $this->invoices->voidOpen();

            $subscription->fill([
                'plan_id' => $plan->getKey(),
                'interval' => $interval,
                'currency' => $plan->currency,
                'amount' => $amount,
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'current_period_start' => $now,
                'current_period_end' => $amount === 0 ? null : ($periodEnd ?? $interval->addTo($now)),
                'grace_ends_at' => null,
                'ended_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'pending_plan_id' => null,
                'pending_interval' => null,
            ])->save();

            $this->announce($subscription, 'billing.plan_granted', [
                'plan' => $plan->key,
                'interval' => $interval->value,
                'period_end' => $subscription->current_period_end?->toIso8601String(),
            ]);

            return $subscription;
        });
    }

    /**
     * Give a trial more time (or a new end). Super Admin panel only.
     *
     * @throws ValidationException
     */
    public function extendTrial(CarbonInterface $until): Subscription
    {
        return DB::transaction(function () use ($until): Subscription {
            $subscription = $this->locked();

            if ($subscription->isFree() || $subscription->isInPaidPeriod()) {
                throw ValidationException::withMessages(['trial_ends_at' => __('Only a plan that has not been paid for yet can be on trial.')]);
            }

            // The invoice for the old trial end is no longer owed; the cycle issues the next one.
            $this->invoices->voidOpen();

            $subscription->fill([
                'status' => SubscriptionStatus::Trialing,
                'trial_ends_at' => $until,
                'current_period_start' => null,
                'current_period_end' => null,
                'grace_ends_at' => null,
                'ended_at' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
            ])->save();

            $this->announce($subscription, 'billing.trial_extended', ['trial_ends_at' => $subscription->trial_ends_at?->toIso8601String()]);

            return $subscription;
        });
    }

    /**
     * A payment is overdue from `$since` on. Used by the billing cycle.
     */
    public function markPastDue(Subscription $subscription, CarbonInterface $since): void
    {
        $subscription->fill([
            'status' => SubscriptionStatus::PastDue,
            'current_period_end' => $since,
            'grace_ends_at' => Carbon::instance($since)->addDays($this->settings->graceDays()),
        ])->save();

        $this->announce($subscription, 'billing.subscription_past_due', [
            'grace_ends_at' => $subscription->grace_ends_at?->toIso8601String(),
        ]);
    }

    /**
     * The subscription is over: unpaid for too long, or canceled. Used by the billing cycle.
     */
    public function end(Subscription $subscription, SubscriptionStatus $as): void
    {
        $subscription->fill([
            'status' => $as,
            'ended_at' => Carbon::now(),
            'grace_ends_at' => null,
            'cancel_at_period_end' => false,
        ])->save();

        $this->announce($subscription, $as === SubscriptionStatus::Canceled ? 'billing.subscription_ended' : 'billing.subscription_expired');
    }

    public function switchToFree(Subscription $subscription, Plan $plan, BillingInterval $interval): void
    {
        $subscription->fill([
            'plan_id' => $plan->getKey(),
            'interval' => $interval,
            'currency' => $plan->currency,
            'amount' => 0,
            'status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
            'current_period_start' => Carbon::now(),
            'current_period_end' => null,
            'grace_ends_at' => null,
            'ended_at' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
            'pending_plan_id' => null,
            'pending_interval' => null,
        ])->save();

        $this->announce($subscription, 'billing.plan_changed', ['plan' => $plan->key, 'interval' => $interval->value]);
    }

    /**
     * @throws ValidationException
     */
    public function price(Plan $plan, BillingInterval $interval): int
    {
        return $plan->amountFor($interval) ?? throw ValidationException::withMessages([
            'interval' => __('This plan is not available for that billing interval.'),
        ]);
    }

    private function schedule(Subscription $subscription, Plan $plan, BillingInterval $interval): PlanChange
    {
        $subscription->fill(['pending_plan_id' => $plan->getKey(), 'pending_interval' => $interval])->save();

        $this->announce($subscription, 'billing.plan_change_scheduled', [
            'plan' => $plan->key,
            'interval' => $interval->value,
            'effective_at' => $subscription->current_period_end?->toIso8601String(),
        ]);

        return PlanChange::scheduled($subscription->current_period_end ?? Carbon::now());
    }

    /**
     * More expensive per month than what the workspace pays now.
     */
    private function isUpgrade(Subscription $subscription, int $amount, BillingInterval $interval): bool
    {
        return $amount * $subscription->interval->months() > $subscription->amount * $interval->months();
    }

    /**
     * What is left of the period that was paid for, in minor units, rounded down.
     */
    private function unusedValue(Subscription $subscription, CarbonInterface $now): int
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if ($start === null || $end === null || $end->lessThanOrEqualTo($now)) {
            return 0;
        }

        $total = $end->getTimestamp() - $start->getTimestamp();
        $left = $end->getTimestamp() - max($now->getTimestamp(), $start->getTimestamp());

        return $total <= 0 ? 0 : intdiv($subscription->amount * $left, $total);
    }

    /**
     * @throws ValidationException
     */
    private function locked(): Subscription
    {
        return Subscription::query()->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['subscription' => __('This workspace has no subscription.')]);
    }

    /**
     * Record the change, tell listeners, and make the rest of this request see it.
     *
     * @param  array<string, mixed>  $properties
     */
    private function announce(Subscription $subscription, string $event, array $properties = []): void
    {
        $this->audit->record($event, $subscription, $properties);

        // What the workspace is entitled to follows from the subscription.
        $this->entitlements->forget();
        $this->currentSubscription->forget();

        SubscriptionUpdated::dispatch($subscription);
    }
}
