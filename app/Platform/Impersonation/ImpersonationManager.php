<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Core\Access\AccessChecker;
use App\Core\Audit\AuditLogger;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\Support\TenantHost;
use App\Core\Tenancy\TenantContext;
use App\Platform\Models\PlatformUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Support access: a Super Admin opening a workspace as its owner.
 *
 * Four things always hold (spec section 17):
 *  - it needs a written reason, kept with the session;
 *  - it is time-limited: the session ends by itself;
 *  - the workspace can see it: start and end are in its own audit trail, and
 *    everything done meanwhile is marked as done through a support session;
 *  - it is fully audited in the platform's trail as well.
 *
 * The session crosses from the admin host to the workspace's host with a
 * one-time token, like a workspace switch; only its hash is stored.
 */
final class ImpersonationManager
{
    /** Session key that marks a workspace session as a support session. */
    public const string SESSION_KEY = 'impersonation';

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantHost $hosts,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Open a support session and return the URL that carries it to the workspace.
     *
     * @throws ValidationException
     */
    public function start(PlatformUser $operator, Tenant $tenant, string $reason, ?string $ipAddress = null): string
    {
        if (! $tenant->isAccessible()) {
            throw ValidationException::withMessages(['reason' => __('Only an active workspace can be opened.')]);
        }

        // Deliberate cross-tenant read: the platform looks up the workspace's owner.
        $owner = TenantMembership::forTenant($tenant)
            ->where('is_owner', true)
            ->where('status', MembershipStatus::Active)
            ->with('user')
            ->first()
            ?->user;

        if ($owner === null) {
            throw ValidationException::withMessages(['reason' => __('This workspace has no active owner to sign in as.')]);
        }

        $token = Str::random(64);
        $now = Carbon::now();

        DB::transaction(function () use ($operator, $tenant, $owner, $reason, $ipAddress, $token, $now): void {
            $impersonation = Impersonation::query()->create([
                'platform_user_id' => $operator->getKey(),
                'tenant_id' => $tenant->getKey(),
                'user_id' => $owner->getKey(),
                'reason' => $reason,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => $now->copy()->addSeconds((int) config('platform.impersonation.token_ttl', 60)),
                // Counted again from the moment the session really starts.
                'expires_at' => $now->copy()->addMinutes($this->ttlMinutes()),
                'ip_address' => $ipAddress,
            ]);

            $this->record($tenant, 'started', $operator, [
                'session' => $impersonation->id,
                'as' => $owner->email,
                'reason' => $reason,
                'minutes' => $this->ttlMinutes(),
            ]);
        });

        return $this->hosts->url($tenant, 'auth/impersonate?token='.$token);
    }

    /**
     * Redeem the token on the workspace's host and sign in as the member.
     * Returns false for anything but a fresh token issued for this workspace.
     */
    public function begin(Request $request, mixed $token, AccessChecker $access): bool
    {
        if (! is_string($token) || preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return false;
        }

        return DB::transaction(function () use ($request, $token, $access): bool {
            $impersonation = Impersonation::query()
                ->where('token_hash', hash('sha256', $token))
                ->where('tenant_id', $this->context->requireId())
                ->with('platformUser:id,email')
                ->lockForUpdate()
                ->first();

            if ($impersonation === null
                || $impersonation->started_at !== null
                || $impersonation->ended_at !== null
                || $impersonation->token_expires_at === null
                || $impersonation->token_expires_at->isPast()) {
                return false;
            }

            $user = User::query()->find($impersonation->user_id);

            if ($user === null || $access->membership($user) === null) {
                return false;
            }

            $now = Carbon::now();
            $expiresAt = $now->copy()->addMinutes($this->ttlMinutes());

            // The token has done its job and cannot be used again.
            $impersonation->update(['token_hash' => null, 'token_expires_at' => null, 'started_at' => $now, 'expires_at' => $expiresAt]);

            Auth::guard('web')->login($user);

            $request->session()->regenerate();
            $request->session()->put(EnsureTenantMember::SESSION_KEY, $this->context->requireId());
            $request->session()->put(self::SESSION_KEY, [
                'id' => $impersonation->id,
                'by' => $impersonation->platformUser->email,
                'expires_at' => $expiresAt->getTimestamp(),
            ]);

            return true;
        });
    }

    /**
     * The support session this workspace session is, if it is one.
     *
     * @return array{id: string, by: string, expires_at: int}|null
     */
    public static function of(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $data = $request->session()->get(self::SESSION_KEY);

        return is_array($data) && isset($data['id'], $data['by'], $data['expires_at']) ? $data : null;
    }

    /**
     * End the support session of this request: record it, and sign out.
     * Returns the tenant it was in, or null when this is no support session.
     */
    public function end(Request $request, bool $expired = false): ?Tenant
    {
        $data = self::of($request);

        if ($data === null) {
            return null;
        }

        $tenant = $this->context->require();

        DB::transaction(function () use ($data, $tenant, $expired): void {
            $impersonation = Impersonation::query()->with('platformUser')->lockForUpdate()->find($data['id']);

            if ($impersonation === null || $impersonation->ended_at !== null) {
                return;
            }

            $impersonation->update(['ended_at' => Carbon::now()]);

            $this->record($tenant, 'ended', $impersonation->platformUser, [
                'session' => $impersonation->id,
                'expired' => $expired,
            ]);
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $tenant;
    }

    private function ttlMinutes(): int
    {
        return max(1, (int) config('platform.impersonation.ttl_minutes', 30));
    }

    /**
     * Once in the platform's trail, once in the workspace's - under the
     * operator's name in both.
     *
     * @param  array<string, mixed>  $properties
     */
    private function record(Tenant $tenant, string $verb, PlatformUser $operator, array $properties): void
    {
        $this->context->central(fn () => $this->audit->record("platform.impersonation.{$verb}", $tenant, $properties, $operator));
        $this->context->run($tenant, fn () => $this->audit->record("support.session_{$verb}", $tenant, $properties, $operator));
    }
}
