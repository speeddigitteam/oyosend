<?php

declare(strict_types=1);

namespace App\Core\Billing\Http\Middleware;

use App\Core\Billing\CurrentSubscription;
use App\Core\Support\Toast;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * `subscribed` - the paywall.
 *
 * A workspace whose subscription has ended (unpaid past the grace period, or
 * canceled) is locked down: its data is kept, but until a plan is taken again
 * only signing in, personal settings and billing are reachable. Those routes
 * simply do not carry this middleware.
 *
 * A workspace without a subscription is not locked - there is nothing it owes.
 */
final class EnsureWorkspaceIsSubscribed
{
    public function __construct(
        private readonly CurrentSubscription $subscription,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $subscription = $this->subscription->get();

        if ($subscription === null || $subscription->grantsAccess()) {
            return $next($request);
        }

        // Whoever looks after billing is taken to where it can be put right.
        if ($request->user('web')?->can('core.billing.view') === true) {
            Toast::warning(__('This workspace is locked until a plan is active again.'));

            return redirect()->route('core.billing.index');
        }

        return Inertia::render('Core/Pages/Errors/WorkspaceLocked', [
            'workspace' => $this->context->require()->name,
            'status' => $subscription->status->value,
        ])->toResponse($request)->setStatusCode(402);
    }
}
