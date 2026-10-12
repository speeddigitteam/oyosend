<?php

declare(strict_types=1);

namespace App\Platform\Impersonation\Http;

use App\Core\Audit\AuditLogger;
use App\Platform\Impersonation\ImpersonationManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a support session inside its limits. Does nothing for an ordinary
 * workspace session.
 *
 *  - The session ends by itself when its time is up.
 *  - It may work in the workspace, not on the person it signs in as: their
 *    profile, password, second factor and language stay untouched and unseen,
 *    and it cannot hop to another workspace that person belongs to.
 *  - It may not create durable access for itself or others: inviting people,
 *    changing members and roles, and changing the workspace's apps. Those
 *    attempts answer 403 and are written to the workspace's trail.
 *  - However it is left, its end is recorded.
 */
final class EnforceSupportSession
{
    /**
     * Routes that belong to the impersonated person rather than to the
     * workspace. The pages are closed as well as the actions: the security
     * page shows the secret of a two-step enrolment that is under way.
     */
    private const array PERSONAL = [
        'core.settings.profile',
        'core.settings.profile.*',
        'core.settings.security',
        'core.settings.password.*',
        'core.settings.two-factor.*',
        'core.locale.update',
        'core.workspaces.switch',
    ];

    /**
     * Changes that would outlive the support session or widen who can get in.
     * Reading these pages stays possible: support needs to see them.
     */
    private const array DURABLE_ACCESS = [
        'core.invitations.store',
        'core.invitations.destroy',
        'core.members.update',
        'core.members.destroy',
        'core.roles.store',
        'core.roles.update',
        'core.roles.destroy',
        'core.apps.install',
        'core.apps.reinstall',
        'core.apps.upgrade',
        'core.apps.purchase',
        'core.apps.activate',
        'core.apps.deactivate',
        'core.apps.uninstall',
        'core.apps.settings.update',
        'core.apps.subscriptions.cancel',
        'core.apps.subscriptions.resume',
    ];

    public function __construct(
        private readonly ImpersonationManager $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $session = ImpersonationManager::of($request);

        if ($session === null) {
            return $next($request);
        }

        if ($session['expires_at'] <= Carbon::now()->getTimestamp()) {
            $this->impersonation->end($request, expired: true);

            return redirect()->route('auth.login');
        }

        // An ordinary sign-out ends the support session like its own "end" button
        // does, so that the workspace's trail says when the operator left.
        if ($request->routeIs('auth.logout')) {
            $this->impersonation->end($request);

            return redirect()->route('auth.login');
        }

        abort_if($request->routeIs(...self::PERSONAL), 403);

        if ($request->routeIs(...self::DURABLE_ACCESS)) {
            $this->audit->record('support.action_blocked', properties: [
                'route' => $request->route()?->getName(),
                'method' => $request->method(),
            ]);

            abort(403, __('This cannot be done from a support session.'));
        }

        return $next($request);
    }
}
