<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Access\AccessChecker;
use App\Core\Billing\CurrentSubscription;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\NavigationBuilder;
use App\Core\Settings\WorkspaceLogo;
use App\Core\Tenancy\TenantContext;
use App\Platform\Impersonation\ImpersonationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Props every workspace page receives.
 *
 * `permissions` and `navigation` exist so the interface can hide what a
 * person cannot use. They are a courtesy to the UI, not access control:
 * every route and action checks again on the server.
 */
final class HandleTenantInertia extends HandleInertiaRequests
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessChecker $access,
        private readonly NavigationBuilder $navigation,
        private readonly CurrentSubscription $subscription,
        private readonly WorkspaceLogo $logo,
    ) {}

    protected function area(): string
    {
        return 'tenant';
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'tenant' => fn (): ?array => $this->tenant(),
            'auth' => fn (): array => $this->auth($request),
            'navigation' => fn (): array => $this->member($request) === null
                ? []
                : $this->navigation->for($this->user($request)),
            'workspaces' => fn (): array => $this->workspaces($request),
            'subscription' => fn (): ?array => $this->member($request) === null ? null : $this->subscription(),
            'supportSession' => fn (): ?array => $this->supportSession($request),
        ];
    }

    protected function regional(Request $request): array
    {
        $tenant = $this->context->tenant();
        $user = $request->user('web');

        return [
            'timezone' => $tenant->timezone ?? (string) config('platform.defaults.timezone'),
            'currency' => $tenant->currency ?? (string) config('platform.defaults.currency'),
            'numerals' => $user instanceof User && $user->preference('numerals') === 'beng' ? 'beng' : 'latn',
        ];
    }

    /**
     * @return array{id: string, name: string, slug: string, logoUrl: string|null}|null
     */
    private function tenant(): ?array
    {
        $tenant = $this->context->tenant();

        return $tenant === null ? null : [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'logoUrl' => $this->logo->url($tenant),
        ];
    }

    /**
     * Where the workspace stands with its plan, for the notice the layout
     * shows (trial ending, payment overdue, locked). Null without a subscription.
     *
     * @return array<string, mixed>|null
     */
    private function subscription(): ?array
    {
        $subscription = $this->subscription->get();

        return $subscription === null ? null : [
            'status' => $subscription->status->value,
            'plan' => $subscription->plan->name,
            'locked' => $subscription->isLocked(),
            'trialEndsAt' => $subscription->trial_ends_at?->toIso8601String(),
            'graceEndsAt' => $subscription->grace_ends_at?->toIso8601String(),
            'cancelAtPeriodEnd' => $subscription->cancel_at_period_end,
            'periodEnd' => $subscription->current_period_end?->toIso8601String(),
        ];
    }

    /**
     * Set while an operator is signed in as a member, so the layout can say
     * so on every page and offer the way out.
     *
     * @return array{by: string, expiresAt: string}|null
     */
    private function supportSession(Request $request): ?array
    {
        $session = ImpersonationManager::of($request);

        return $session === null ? null : [
            'by' => $session['by'],
            'expiresAt' => Carbon::createFromTimestamp($session['expires_at'])->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function auth(Request $request): array
    {
        $membership = $this->member($request);

        if ($membership === null) {
            return ['user' => null, 'membership' => null, 'permissions' => []];
        }

        $user = $this->user($request);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'initials' => $user->initials(),
                'locale' => $user->locale,
                'twoFactorEnabled' => $user->hasEnabledTwoFactor(),
            ],
            'membership' => [
                'id' => $membership->id,
                'isOwner' => $membership->is_owner,
            ],
            'permissions' => array_keys($this->access->effectivePermissions($user)),
        ];
    }

    /**
     * The other workspaces this person can switch to.
     *
     * @return list<array{name: string, slug: string, current: bool}>
     */
    private function workspaces(Request $request): array
    {
        // A support session stays in the workspace it was opened for.
        if ($this->member($request) === null || ImpersonationManager::of($request) !== null) {
            return [];
        }

        $currentId = $this->context->id();

        return $this->user($request)
            ->activeMemberships()
            ->with('tenant:id,name,slug,status')
            ->get()
            // A deleted workspace keeps its membership rows but no longer loads.
            ->filter(fn (TenantMembership $membership): bool => $membership->tenant?->isAccessible() === true)
            ->map(fn (TenantMembership $membership): array => [
                'name' => $membership->tenant->name,
                'slug' => $membership->tenant->slug,
                'current' => $membership->tenant_id === $currentId,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * The verified membership, set by EnsureTenantMember. Absent on guest pages.
     */
    private function member(Request $request): ?TenantMembership
    {
        $membership = $request->attributes->get('membership');

        return $membership instanceof TenantMembership ? $membership : null;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('web');

        return $user;
    }
}
