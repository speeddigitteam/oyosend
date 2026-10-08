<?php

declare(strict_types=1);

namespace App\Core\Identity\Http\Middleware;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * An authenticated session is only valid for the workspace it was created in,
 * and only while the person is still an active member of it.
 *
 * Checked on every request, so removing or suspending a member takes effect
 * immediately rather than when their session expires.
 */
final class EnsureTenantMember
{
    public const string SESSION_KEY = 'auth.tenant_id';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessChecker $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $tenantId = $this->context->requireId();

        $membership = $user instanceof User
            && $request->session()->get(self::SESSION_KEY) === $tenantId
                ? $this->access->membership($user)
                : null;

        if ($membership === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->guest(route('auth.login'));
        }

        // Throttled write: enough to show "last seen" without a query per request.
        if ($membership->last_seen_at === null || $membership->last_seen_at->lt(Carbon::now()->subMinutes(5))) {
            $membership->timestamps = false;
            $membership->forceFill(['last_seen_at' => Carbon::now()])->saveQuietly();
            $membership->timestamps = true;
        }

        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
