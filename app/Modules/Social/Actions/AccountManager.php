<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Billing\UsageLimits;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\AttemptOutcome;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Events\AccountNeedsReauthorization;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\Exceptions\ReauthorizationRequired;
use App\Modules\Social\Platforms\ProfileConnection;
use App\Modules\Social\Platforms\SocialPlatform;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\TokenSet;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The connected-account state machine:
 *
 *   (authorise) -> connected -> needs_reauthorization -> (authorise again) -> connected
 *                      \-> disconnected  (tokens discarded; reconnecting counts against the plan again)
 *
 * Tokens are written only here, always through the model's encrypted cast, and are never put in
 * an exception message, an audit property, an event or a log line.
 */
final class AccountManager
{
    public const string LIMIT = 'social.connected_accounts';

    private const int SELECTION_TTL_MINUTES = 10;

    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly UsageLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** The platform, only when it can be used right now. */
    public function usablePlatform(string $key): SocialPlatform
    {
        $platform = $this->platforms->find($key);

        if ($platform === null) {
            throw ValidationException::withMessages(['platform' => __('Unknown platform.')]);
        }

        $availability = $platform->availability();

        if (! $availability->available) {
            throw ValidationException::withMessages(['platform' => $availability->reason ?? __('This platform is not available.')]);
        }

        return $platform;
    }

    /**
     * Refuse to start connecting when the plan has no seat left (re-authorising a held seat is fine).
     *
     * @throws ValidationException
     */
    public function assertSeatAvailable(?SocialAccount $reauthorize = null): void
    {
        if ($reauthorize === null || ! $reauthorize->status->countsTowardLimit()) {
            $this->limits->ensure(self::LIMIT, 1, 'platform');
        }
    }

    /**
     * Exchange an authorization code for the profiles it grants.
     *
     * @return list<ProfileConnection>
     */
    public function exchange(SocialPlatform $platform, string $code, string $redirectUri, ?string $codeVerifier): array
    {
        try {
            return $platform->completeAuthorization($code, $redirectUri, $codeVerifier);
        } catch (ReauthorizationRequired|PublishFailed|PlatformUnavailable $exception) {
            throw ValidationException::withMessages(['platform' => $exception->getMessage()]);
        }
    }

    /**
     * Save a granted profile as a connected account (a new one, or the same profile again).
     * `$expectedAccount` is set when the person is re-authorising one account: only that profile is accepted.
     */
    public function connect(string $platformKey, ProfileConnection $profile, ?TenantMembership $by, ?SocialAccount $expectedAccount = null): SocialAccount
    {
        if ($expectedAccount !== null && ($expectedAccount->platform !== $platformKey || $expectedAccount->external_id !== $profile->externalId)) {
            throw ValidationException::withMessages(['platform' => __('The authorised profile is a different account from the one being reconnected.')]);
        }

        try {
            return DB::transaction(function () use ($platformKey, $profile, $by): SocialAccount {
                $account = SocialAccount::query()
                    ->where('platform', $platformKey)->where('external_id', $profile->externalId)
                    ->lockForUpdate()->first();

                $holdsSeat = $account !== null && $account->status->countsTowardLimit();

                if (! $holdsSeat) {
                    $this->limits->ensure(self::LIMIT, 1, 'platform');
                }

                $account ??= new SocialAccount(['platform' => $platformKey, 'external_id' => $profile->externalId]);
                $account->fill([
                    'name' => $profile->name,
                    'handle' => $profile->handle,
                    'avatar_url' => $profile->avatarUrl,
                    'account_type' => $profile->type,
                    'status' => AccountStatus::Connected,
                    'status_reason' => null,
                    'last_error' => null,
                    'connected_by' => $by->id ?? $account->connected_by,
                    'connected_at' => now(),
                ]);
                $this->storeTokens($account, $profile->tokens);
                $account->save();

                $this->audit->record('social.account.connected', $account, ['platform' => $platformKey]);

                return $account;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent callback saved the same profile first; use its row.
            return SocialAccount::query()->where('platform', $platformKey)->where('external_id', $profile->externalId)->firstOrFail();
        }
    }

    /**
     * Park several granted profiles until the person picks which to connect. The tokens are kept
     * encrypted in the workspace cache for ten minutes and bound to the person who authorised.
     *
     * @param  list<ProfileConnection>  $profiles
     */
    public function stashSelection(string $platformKey, array $profiles, string $userId): string
    {
        $id = (string) Str::ulid();
        $payload = array_map(static fn (ProfileConnection $profile): array => [
            'external_id' => $profile->externalId,
            'name' => $profile->name,
            'handle' => $profile->handle,
            'avatar_url' => $profile->avatarUrl,
            'type' => $profile->type,
            'access_token' => $profile->tokens->accessToken,
            'refresh_token' => $profile->tokens->refreshToken,
            'expires_at' => $profile->tokens->expiresAt?->toIso8601String(),
            'refresh_expires_at' => $profile->tokens->refreshExpiresAt?->toIso8601String(),
            'scopes' => $profile->tokens->scopes,
        ], $profiles);

        Cache::put(
            $this->selectionKey($id),
            Crypt::encryptString((string) json_encode(['user' => $userId, 'platform' => $platformKey, 'profiles' => $payload])),
            now()->addMinutes(self::SELECTION_TTL_MINUTES),
        );

        return $id;
    }

    /**
     * @return array{platform: string, profiles: list<ProfileConnection>}|null
     */
    public function selection(string $id, string $userId, bool $consume = false): ?array
    {
        $encrypted = Cache::get($this->selectionKey($id));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            /** @var array{user: string, platform: string, profiles: list<array<string, mixed>>} $data */
            $data = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! hash_equals($data['user'], $userId)) {
            return null;
        }

        if ($consume) {
            Cache::forget($this->selectionKey($id));
        }

        $profiles = [];

        foreach ($data['profiles'] as $row) {
            $profiles[] = new ProfileConnection(
                externalId: (string) $row['external_id'],
                name: (string) $row['name'],
                handle: $row['handle'] === null ? null : (string) $row['handle'],
                avatarUrl: $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
                type: (string) $row['type'],
                tokens: new TokenSet(
                    accessToken: (string) $row['access_token'],
                    refreshToken: $row['refresh_token'] === null ? null : (string) $row['refresh_token'],
                    expiresAt: $row['expires_at'] === null ? null : CarbonImmutable::parse((string) $row['expires_at']),
                    refreshExpiresAt: $row['refresh_expires_at'] === null ? null : CarbonImmutable::parse((string) $row['refresh_expires_at']),
                    scopes: array_values(array_map('strval', (array) $row['scopes'])),
                ),
            );
        }

        return ['platform' => $data['platform'], 'profiles' => $profiles];
    }

    /** Stop using an account. Its tokens are discarded and its queued posts fail with a clear reason. */
    public function disconnect(SocialAccount $account): SocialAccount
    {
        return DB::transaction(function () use ($account): SocialAccount {
            $locked = SocialAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->status === AccountStatus::Disconnected) {
                return $locked;
            }

            $locked->fill([
                'status' => AccountStatus::Disconnected,
                'status_reason' => null,
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'refresh_token_expires_at' => null,
            ])->save();

            $this->failQueuedTargets($locked, 'account_disconnected', __('The account was disconnected.'));
            $this->audit->record('social.account.disconnected', $locked, ['platform' => $locked->platform]);

            return $locked;
        });
    }

    /** The platform no longer accepts the stored authorisation. Tokens are dropped; the person must connect again. */
    public function markNeedsReauthorization(SocialAccount $account, string $reason): void
    {
        DB::transaction(function () use ($account, $reason): void {
            $locked = SocialAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->status !== AccountStatus::Connected) {
                return;
            }

            $locked->fill([
                'status' => AccountStatus::NeedsReauthorization,
                'status_reason' => Str::limit($reason, 480, ''),
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'refresh_token_expires_at' => null,
            ])->save();

            $this->audit->record('social.account.needs_reauthorization', $locked, ['platform' => $locked->platform]);
            event(AccountNeedsReauthorization::from($locked));
        });
    }

    /**
     * Credentials with a token that is valid now: refreshes it when it expires within the margin.
     *
     * @throws PublishFailed with `reauthorize` when the platform rejects the stored authorisation
     */
    public function credentials(SocialAccount $account, ?int $marginMinutes = null): AccountCredentials
    {
        $margin = CarbonImmutable::now()->addMinutes($marginMinutes ?? (int) config('social.publishing.token_refresh_margin_minutes', 10));

        if ($account->status !== AccountStatus::Connected || $account->access_token === null) {
            throw PublishFailed::reauthorization(__('The account must be authorised again.'));
        }

        if ($account->token_expires_at === null || $account->token_expires_at->gt($margin)) {
            return new AccountCredentials($account->external_id, $account->access_token);
        }

        $result = DB::transaction(function () use ($account, $margin): AccountCredentials|string {
            // Refresh tokens can be single-use: only one worker may rotate them.
            $locked = SocialAccount::query()->lockForUpdate()->findOrFail($account->id);

            if ($locked->status !== AccountStatus::Connected || $locked->access_token === null) {
                return __('The account must be authorised again.');
            }

            if ($locked->token_expires_at === null || $locked->token_expires_at->gt($margin)) {
                return new AccountCredentials($locked->external_id, $locked->access_token);
            }

            if ($locked->refresh_token === null) {
                return $this->failAuthorisation($locked, __('The access token expired and cannot be renewed. Authorise the account again.'));
            }

            try {
                $tokens = $this->platforms->get($locked->platform)->refresh($locked->refresh_token);
            } catch (ReauthorizationRequired $exception) {
                return $this->failAuthorisation($locked, $exception->getMessage());
            } catch (PlatformUnavailable|PublishFailed $exception) {
                throw PublishFailed::rateLimited($exception->getMessage(), 300);
            }

            $this->storeTokens($locked, $tokens);
            $locked->last_refreshed_at = now();
            $locked->save();

            return new AccountCredentials($locked->external_id, $tokens->accessToken);
        });

        if (is_string($result)) {
            throw PublishFailed::reauthorization($result);
        }

        return $result;
    }

    /**
     * Fail a queued target (and roll the post up) for a reason that will not fix itself.
     */
    private function failQueuedTargets(SocialAccount $account, string $code, string $message): void
    {
        $targets = PostTarget::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [TargetStatus::Pending->value, TargetStatus::Processing->value])
            ->get();

        foreach ($targets as $target) {
            // Media already with the platform may still go live: a person has to look.
            $inFlight = $target->status === TargetStatus::Processing;
            $target->fill([
                'status' => TargetStatus::Failed,
                'next_attempt_at' => null,
                'last_error' => $message,
                'last_error_code' => $code,
                'needs_manual_check' => $inFlight,
                'external_id' => null,
                'remote_id' => null,
                'remote_state' => null,
                'upload_offset' => 0,
                'remote_meta' => null,
                'remote_started_at' => null,
            ])->save();

            PublishAttempt::query()->create([
                'target_id' => $target->id,
                'attempt' => $target->attempts,
                'outcome' => AttemptOutcome::Failed,
                'error_code' => $code,
                'message' => $message,
            ]);

            app(PostRollup::class)->refresh($target->post_id);
        }
    }

    private function failAuthorisation(SocialAccount $account, string $reason): string
    {
        $account->fill([
            'status' => AccountStatus::NeedsReauthorization,
            'status_reason' => Str::limit($reason, 480, ''),
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'refresh_token_expires_at' => null,
        ])->save();
        $this->audit->record('social.account.needs_reauthorization', $account, ['platform' => $account->platform]);
        event(AccountNeedsReauthorization::from($account));

        return $reason;
    }

    private function storeTokens(SocialAccount $account, TokenSet $tokens): void
    {
        $account->access_token = $tokens->accessToken;

        // A refresh that returns no new refresh token keeps the one we hold.
        if ($tokens->refreshToken !== null) {
            $account->refresh_token = $tokens->refreshToken;
        }

        $account->token_expires_at = $tokens->expiresAt === null ? null : Carbon::instance($tokens->expiresAt);
        $account->refresh_token_expires_at = $tokens->refreshExpiresAt === null
            ? $account->refresh_token_expires_at
            : Carbon::instance($tokens->refreshExpiresAt);
        $account->scopes = $tokens->scopes === [] ? $account->scopes : $tokens->scopes;
    }

    private function selectionKey(string $id): string
    {
        return 'social:selection:'.$id;
    }
}
