<?php

declare(strict_types=1);

namespace App\Core\Billing\Http\Controllers;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Models\Invoice;
use App\Core\Billing\Models\Payment;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\SubscriptionManager;
use App\Core\Billing\Support\BillingPresenter;
use App\Core\Billing\Support\PlanChange;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\User;
use App\Core\Settings\PlatformSettings;
use App\Core\Support\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workspace's own view of what it pays the platform: its plan, what it
 * uses of it, and its invoices.
 */
final class BillingController
{
    public function __construct(private readonly SubscriptionManager $subscriptions) {}

    public function index(Request $request, UsageLimits $limits, GatewayRegistry $gateways, PlatformSettings $settings): Response
    {
        /** @var User $actor */
        $actor = $request->user('web');

        $subscription = $this->subscriptions->current();
        // Base-plan billing and App Store billing are independent. App
        // invoices remain in the history and are opened from their own row.
        $open = Invoice::query()->open()->whereNotNull('subscription_id')->latest('issued_at')->first();

        // The payer comes back from a gateway with `?payment={reference}`.
        $reference = $request->query('payment');
        $returned = is_string($reference) && preg_match('/^[0-9A-Za-z]{26}$/', $reference) === 1
            ? Payment::query()->where('reference', $reference)->first()
            : null;

        return Inertia::render('Core/Pages/Billing/Index', [
            'subscription' => BillingPresenter::subscription($subscription),
            'plans' => Plan::query()
                ->offered()
                // The current plan is listed even when it is no longer sold or was never public.
                ->when($subscription !== null, fn ($query) => $query->orWhere('id', $subscription?->plan_id))
                ->get()
                ->sortBy(['sort_order', 'name'])
                ->values()
                ->map(fn (Plan $plan): array => [
                    ...BillingPresenter::plan($plan),
                    'current' => $subscription !== null && $subscription->plan_id === $plan->id,
                ])
                ->all(),
            'usage' => $limits->overview(),
            'invoices' => Invoice::query()
                ->latest('issued_at')
                ->latest('id')
                ->limit(24)
                ->get()
                ->map(BillingPresenter::invoice(...))
                ->all(),
            'openInvoice' => $open === null ? null : BillingPresenter::invoice($open),
            'gateways' => $open === null ? [] : array_map(
                static fn ($gateway): array => ['key' => $gateway->key(), 'name' => $gateway->name()],
                $gateways->usable($open->currency),
            ),
            'offlineInstructions' => $settings->offlineInstructions(),
            'returnedPayment' => $returned === null ? null : BillingPresenter::payment($returned),
            'canManage' => $actor->can('core.billing.manage'),
        ]);
    }

    public function change(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'plan_id' => ['required', 'string', 'size:26'],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
        ]);

        $current = $this->subscriptions->current();

        // A plan the workspace may choose for itself - or the one it is already on (another interval).
        $plan = Plan::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->where('is_public', true)->orWhere('id', $current?->plan_id))
            ->findOrFail($input['plan_id']);

        $change = $this->subscriptions->changePlan($plan, BillingInterval::from($input['interval']));

        if ($change->outcome === PlanChange::AWAITING_PAYMENT && $change->invoice !== null) {
            Toast::info(__('The :plan plan starts as soon as this invoice is paid.', ['plan' => $plan->name]));

            return redirect()->route('core.billing.invoices.show', ['invoice' => $change->invoice->getKey()]);
        }

        if ($change->outcome === PlanChange::SCHEDULED) {
            Toast::success(__('The :plan plan starts when the period you have paid for ends.', ['plan' => $plan->name]));
        } else {
            Toast::success(__('You are now on the :plan plan.', ['plan' => $plan->name]));
        }

        return redirect()->route('core.billing.index');
    }

    public function cancel(): RedirectResponse
    {
        $subscription = $this->subscriptions->cancel();

        Toast::success($subscription->isLocked()
            ? __('The subscription has been canceled.')
            : __('The subscription will end when the current period is over.'));

        return redirect()->route('core.billing.index');
    }

    public function resume(): RedirectResponse
    {
        $this->subscriptions->resume();

        Toast::success(__('The subscription will continue.'));

        return redirect()->route('core.billing.index');
    }
}
