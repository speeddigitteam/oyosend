<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Models\Plan;
use App\Core\Billing\SubscriptionManager;
use App\Core\Support\Toast;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * What an operator may do to a workspace's subscription that the workspace
 * cannot do itself: put it on any plan without an invoice, and move the end
 * of its trial. Both are audited in the workspace's trail under the
 * operator's name.
 */
final class TenantSubscriptionController
{
    public function __construct(
        private readonly SubscriptionManager $subscriptions,
        private readonly TenantContext $context,
    ) {}

    /**
     * Put the workspace on a plan at once, without an invoice.
     */
    public function grant(Request $request, Tenant $workspace): RedirectResponse
    {
        $input = $request->validate([
            'plan_id' => ['required', 'string', 'size:26'],
            'interval' => ['required', Rule::enum(BillingInterval::class)],
            // When a paid plan given this way runs out. Empty: one interval from now.
            'period_end' => ['nullable', 'date', 'after:today'],
        ], [
            // The rule's own message would quote the word "today" as it is written here.
            'period_end.after' => __('Choose a date after today.'),
        ]);

        // Any plan, public or not: this is how a custom deal is given.
        $plan = Plan::query()->findOrFail($input['plan_id']);
        $interval = BillingInterval::from($input['interval']);
        $periodEnd = isset($input['period_end']) ? Carbon::parse($input['period_end'], $workspace->timezone)->endOfDay()->utc() : null;

        $this->context->run($workspace, function () use ($plan, $interval, $periodEnd): void {
            $this->subscriptions->grant($plan, $interval, $periodEnd);
        });

        Toast::success(__('The workspace is now on the :plan plan.', ['plan' => $plan->name]));

        return back();
    }

    public function extendTrial(Request $request, Tenant $workspace): RedirectResponse
    {
        $input = $request->validate([
            'trial_ends_at' => ['required', 'date', 'after:today'],
        ], [
            'trial_ends_at.after' => __('Choose a date after today.'),
        ]);

        // The end of that day on the workspace's own clock.
        $until = Carbon::parse($input['trial_ends_at'], $workspace->timezone)->endOfDay()->utc();

        $this->context->run($workspace, function () use ($until): void {
            $this->subscriptions->extendTrial($until);
        });

        Toast::success(__('Trial extended.'));

        return back();
    }
}
