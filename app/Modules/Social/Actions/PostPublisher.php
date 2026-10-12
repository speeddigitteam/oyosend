<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Social\Enums\AttemptOutcome;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\SocialPlatforms;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Publishes queued targets. It is only ever called from the entitlement-gated scheduled job.
 *
 * Guarantees:
 *  - a target is claimed with an atomic compare-and-set, so two workers never publish it twice;
 *  - a target that already has an external id is never published again;
 *  - a retry happens only when the platform provably did not process the request (rate limit,
 *    connection refused). Anything uncertain fails the target and asks a person to check;
 *  - per-account (hourly, workspace setting) and per-workspace (per minute) rate limits defer a
 *    target without spending an attempt.
 */
final class PostPublisher
{
    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly AccountManager $accounts,
        private readonly PostRollup $rollup,
        private readonly SocialSettings $settings,
        private readonly TenantStorage $storage,
    ) {}

    /**
     * Process what is due now. Returns how many targets were handled (published, retried or failed).
     */
    public function runDue(?int $limit = null): int
    {
        $this->flagStuck();

        $ids = PostTarget::query()
            ->where('status', TargetStatus::Pending->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value]))
            ->orderBy('next_attempt_at')
            ->limit($limit ?? (int) config('social.publishing.batch', 50))
            ->pluck('id');

        $handled = 0;

        foreach ($ids as $id) {
            if ($this->publishTarget((string) $id) !== null) {
                $handled++;
            }
        }

        return $handled;
    }

    /**
     * @return AttemptOutcome|null null when the target was not ours to publish (claimed elsewhere or not due)
     */
    public function publishTarget(string $targetId): ?AttemptOutcome
    {
        $target = PostTarget::query()->with(['post', 'account'])->find($targetId);

        if ($target === null || $target->status !== TargetStatus::Pending || $target->external_id !== null) {
            return null;
        }

        $account = $target->account;
        $hourly = max(1, $this->settings->current()->account_hourly_limit);
        $accountKey = 'social:account:'.$account->id;
        $tenantKey = 'social:publish';
        $perMinute = max(1, (int) config('social.publishing.tenant_per_minute', 30));

        if (RateLimiter::tooManyAttempts($tenantKey, $perMinute)) {
            return null;
        }

        if (RateLimiter::tooManyAttempts($accountKey, $hourly)) {
            // Wait for the account's window; no attempt is spent.
            $target->update(['next_attempt_at' => now()->addSeconds(max(60, RateLimiter::availableIn($accountKey)))]);

            return AttemptOutcome::Deferred;
        }

        $claimed = PostTarget::query()
            ->whereKey($target->id)
            ->where('status', TargetStatus::Pending->value)
            ->update(['status' => TargetStatus::Publishing->value, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed !== 1) {
            return null;
        }

        Post::query()->whereKey($target->post_id)
            ->where('status', PostStatus::Scheduled->value)->update(['status' => PostStatus::Publishing->value]);

        $target->refresh();
        $secret = $account->access_token;

        try {
            $platform = $this->platforms->get($account->platform);
            $credentials = $this->accounts->credentials($account);
            $published = $platform->publish($credentials, $this->payload($target));
        } catch (PublishFailed $failure) {
            return $this->fail($target, $failure, $secret);
        } catch (PlatformUnavailable $exception) {
            return $this->fail($target, new PublishFailed($exception->getMessage(), 'platform_unavailable'), $secret);
        } catch (Throwable $exception) {
            // Nothing from the exception is stored: it may carry request details.
            Log::warning('Social publish raised an unexpected error.', ['target' => $target->id, 'type' => $exception::class]);

            return $this->fail($target, PublishFailed::unknownOutcome(
                'An unexpected error stopped the publish. Check the account before trying again.',
                'unexpected_error',
            ), $secret);
        }

        RateLimiter::hit($accountKey, 3600);
        RateLimiter::hit($tenantKey, 60);

        DB::transaction(function () use ($target, $published): void {
            $target->fill([
                'status' => TargetStatus::Published,
                'external_id' => Str::limit($published->externalId, 190, ''),
                'external_url' => $published->url === null ? null : Str::limit($published->url, 490, ''),
                'published_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
                'last_error_code' => null,
                'needs_manual_check' => false,
            ])->save();
            $target->account->forceFill(['last_used_at' => now(), 'last_error' => null])->save();
            $this->log($target, AttemptOutcome::Success);
            $this->rollup->refresh($target->post_id);
        });

        return AttemptOutcome::Success;
    }

    /**
     * Targets claimed long ago whose worker never reported back. They are not retried: the
     * platform may have the post, so a person has to look.
     */
    public function flagStuck(): int
    {
        $cutoff = now()->subMinutes((int) config('social.publishing.stuck_after_minutes', 15));
        $stuck = PostTarget::query()
            ->where('status', TargetStatus::Publishing->value)
            ->where('claimed_at', '<', $cutoff)
            ->get();

        foreach ($stuck as $target) {
            DB::transaction(function () use ($target): void {
                $target->fill([
                    'status' => TargetStatus::Failed,
                    'last_error' => __('The publish did not report back. Check the account before trying again.'),
                    'last_error_code' => 'outcome_unknown',
                    'needs_manual_check' => true,
                    'next_attempt_at' => null,
                ])->save();
                $this->log($target, AttemptOutcome::Failed, 'outcome_unknown', (string) $target->last_error);
                $this->rollup->refresh($target->post_id);
            });
        }

        return $stuck->count();
    }

    private function fail(PostTarget $target, PublishFailed $failure, ?string $secret): AttemptOutcome
    {
        $message = Str::limit($this->redact($failure->getMessage(), $secret), 480, '');
        $max = max(1, (int) config('social.publishing.max_attempts', 4));
        $backoff = array_values(array_map('intval', (array) config('social.publishing.backoff_seconds', [60, 300, 1800])));
        $willRetry = $failure->retryable && $target->attempts < $max;

        if ($failure->reauthorize) {
            $this->accounts->markNeedsReauthorization($target->account, $message);
        }

        return DB::transaction(function () use ($target, $failure, $message, $backoff, $willRetry): AttemptOutcome {
            if ($willRetry) {
                $delay = $failure->retryAfterSeconds
                    ?? ($backoff[min($target->attempts, count($backoff)) - 1] ?? 60);
                $target->fill([
                    'status' => TargetStatus::Pending,
                    'next_attempt_at' => now()->addSeconds(max(1, $delay)),
                    'claimed_at' => null,
                    'last_error' => $message,
                    'last_error_code' => $failure->errorCode,
                ])->save();
                $this->log($target, AttemptOutcome::Retry, $failure->errorCode, $message);
                $this->rollup->refresh($target->post_id);

                return AttemptOutcome::Retry;
            }

            $target->fill([
                'status' => TargetStatus::Failed,
                'next_attempt_at' => null,
                'claimed_at' => null,
                'last_error' => $message,
                'last_error_code' => $failure->errorCode,
                'needs_manual_check' => $failure->outcomeUnknown,
            ])->save();
            $this->log($target, AttemptOutcome::Failed, $failure->errorCode, $message);
            $this->rollup->refresh($target->post_id);

            return AttemptOutcome::Failed;
        });
    }

    private function payload(PostTarget $target): PublishPayload
    {
        $post = $target->post;
        $media = $post->mediaLinks()->with('media')->get()->map(function (PostMedia $link): MediaFile {
            $item = $link->media;

            return new MediaFile(
                name: $item->name,
                mime: $item->mime,
                isVideo: $item->kind === MediaKind::Video,
                altText: $item->alt_text,
                reader: fn (): string => $this->storage->get($item->path)
                    ?? throw PublishFailed::rejected('A media file is missing from storage.', 'media_missing'),
            );
        })->values()->all();

        return new PublishPayload(
            text: $post->composedBody($target->body_override),
            linkUrl: $post->link_url,
            media: $media,
            idempotencyKey: $target->idempotency_key,
        );
    }

    private function log(PostTarget $target, AttemptOutcome $outcome, ?string $code = null, ?string $message = null): void
    {
        PublishAttempt::query()->create([
            'target_id' => $target->id,
            'attempt' => $target->attempts,
            'outcome' => $outcome,
            'error_code' => $code,
            'message' => $message,
        ]);
    }

    private function redact(string $message, ?string $secret): string
    {
        return $secret === null || $secret === '' ? $message : str_replace($secret, '[redacted]', $message);
    }
}
