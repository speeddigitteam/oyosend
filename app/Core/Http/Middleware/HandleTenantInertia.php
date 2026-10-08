<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Modules\NavigationBuilder;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;

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
     * @return array{id: string, name: string, slug: string}|null
     */
    private function tenant(): ?array
    {
        $tenant = $this->context->tenant();

        return $tenant === null ? null : [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
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
        if ($this->member($request) === null) {
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
