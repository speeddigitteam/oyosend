<?php

declare(strict_types=1);

namespace App\Platform\Impersonation\Http;

use App\Core\Access\AccessChecker;
use App\Core\Support\Toast;
use App\Platform\Impersonation\ImpersonationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The workspace side of a support session: where it arrives and where it is
 * ended. It runs on the workspace's own host, so the tenant comes from the
 * hostname as for every other workspace request.
 */
final class WorkspaceImpersonationController
{
    public function __construct(private readonly ImpersonationManager $impersonation) {}

    public function begin(Request $request, AccessChecker $access): RedirectResponse
    {
        if (! $this->impersonation->begin($request, $request->query('token'), $access)) {
            return redirect()->route('auth.login');
        }

        return redirect()->route('core.dashboard');
    }

    public function stop(Request $request): Response
    {
        $tenant = $this->impersonation->end($request);

        if ($tenant === null) {
            Toast::info(__('This is not a support session.'));

            return back();
        }

        // Back to where the session was opened from, on the admin host.
        return Inertia::location(route('platform.tenants.show', ['workspace' => $tenant->getKey()]));
    }
}
