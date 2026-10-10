<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Core\Tenancy\Models\Tenant;
use App\Platform\Impersonation\ImpersonationManager;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens a support session: the operator states why, and is taken into the
 * workspace as its owner for a limited time. See {@see ImpersonationManager}.
 */
final class ImpersonationController
{
    public function store(Request $request, Tenant $workspace, ImpersonationManager $impersonation): Response
    {
        $input = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        /** @var PlatformUser $operator */
        $operator = $request->user('platform');

        // Another origin: Inertia has to leave the panel for it.
        return Inertia::location($impersonation->start($operator, $workspace, $input['reason'], $request->ip()));
    }
}
