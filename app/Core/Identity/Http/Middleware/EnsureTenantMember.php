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
use Illuminate\Support\Facades\Cookie;
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

    /** Names the workspace a "remember me" cookie was issued in; set and cleared together with it. */
    public const string REMEMBER_COOKIE = 'remember_workspace';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessChecker $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');
        $tenantId = $this->context->requireId();

        $membership = $user instanceof User
            && $this->boundTenantId($request) === $tenantId
                ? $this->access->membership($user)
                : null;

        if ($membership === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Cookie::queue(Cookie::forget(self::REMEMBER_COOKIE));

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

    /**
     * The workspace this session is bound to.
     *
     * A session restored from the "remember me" cookie is brand new and has
     * no binding yet. It is bound to this workspace only if the cookie was
     * issued here, which the companion cookie set at sign-in says (encrypted,
     * like every cookie). The framework's own cookie names a person and no
     * workspace: on its own, a copy of it taken from one workspace would sign
     * that person in to every other workspace they belong to, skipping the
     * password and second factor there.
     *
     * The membership check that follows still applies, and a session that is
     * already bound elsewhere is never re-bound.
     */
    private function boundTenantId(Request $request): ?string
    {
        $session = $request->session();

        if (! $session->has(self::SESSION_KEY) && Auth::guard('web')->viaRemember() && $this->rememberedHere($request)) {
            $session->put(self::SESSION_KEY, $this->context->requireId());
        }

        $bound = $session->get(self::SESSION_KEY);

        return is_string($bound) ? $bound : null;
    }

    private function rememberedHere(Request $request): bool
    {
        $issuedIn = $request->cookie(self::REMEMBER_COOKIE);

        return is_string($issuedIn) && hash_equals($this->context->requireId(), $issuedIn);
    }
}
