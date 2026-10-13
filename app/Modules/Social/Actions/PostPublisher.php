<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Tenancy\Storage\TenantStorage;
use App\Modules\Social\Enums\AttemptOutcome;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Enums\PostStatus;
use App\Modules\Social\Enums\PostType;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\PublishAttempt;
use App\Modules\Social\Platforms\AccountCredentials;
use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\MediaFile;
use App\Modules\Social\Platforms\PublishedPost;
use App\Modules\Social\Platforms\PublishPayload;
use App\Modules\Social\Platforms\PublishSession;
use App\Modules\Social\Platforms\SocialPlatforms;
use App\Modules\Social\Platforms\StepwisePublisher;
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
 *    target without spending an attempt;
 *  - a video, Reel or multi-photo post is published in steps ({@see StepwisePublisher}): the upload is
 *    resumed from the saved offset on the next run, the target is `processing` until the platform says
 *    the media is ready, and the final "publish" call is never repeated (its id is stored at once).
 */
final class PostPublisher
{
    /** Unix time after which a run starts no new request (the job has a hard timeout). */
    private float $deadline = 0.0;

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
        $this->deadline = microtime(true) + max(0.0, (float) config('social.publishing.run_budget_seconds', 35));
        $this->flagStuck();
        $this->flagStaleProcessing();

        $resume = PostTarget::query()
            ->where('status', TargetStatus::Processing->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value]))
            ->orderBy('next_attempt_at')
            ->limit($limit ?? (int) config('social.publishing.batch', 50))
            ->pluck('id');

        $ids = PostTarget::query()
            ->where('status', TargetStatus::Pending->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value]))
            ->orderBy('next_attempt_at')
            ->limit($limit ?? (int) config('social.publishing.batch', 50))
            ->pluck('id');

        $handled = 0;

        foreach ($resume as $id) {
            // At least one target per run, so a tiny budget still makes progress.
            if ($handled > 0 && microtime(true) >= $this->deadline) {
                return $handled;
            }

            if ($this->resumeTarget((string) $id) !== null) {
                $handled++;
            }
        }

        foreach ($ids as $id) {
            if ($handled > 0 && microtime(true) >= $this->deadline) {
                break;
            }

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
            // Re-check what made it due: a member may have unscheduled, rejected, edited or rescheduled the
            // post since the ids were read, and what is published must still be what was approved.
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value]))
            ->update(['status' => TargetStatus::Publishing->value, 'claimed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);

        if ($claimed !== 1) {
            return null;
        }

        Post::query()->whereKey($target->post_id)
            ->where('status', PostStatus::Scheduled->value)->update(['status' => PostStatus::Publishing->value]);

        $target->refresh();

        return $this->execute($target);
    }

    /**
     * Continue a target whose media is being uploaded or processed. Only ever called for a `processing`
     * target that is due; the claim is a compare-and-set so overlapping runs do not both continue it.
     *
     * @return AttemptOutcome|null null when the target was not ours to continue
     */
    public function resumeTarget(string $targetId): ?AttemptOutcome
    {
        $target = PostTarget::query()->with(['post', 'account'])->find($targetId);

        if ($target === null || $target->status !== TargetStatus::Processing) {
            return null;
        }

        $claimed = PostTarget::query()
            ->whereKey($target->id)
            ->where('status', TargetStatus::Processing->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->whereHas('post', fn ($query) => $query->whereIn('status', [PostStatus::Scheduled->value, PostStatus::Publishing->value]))
            ->update(['status' => TargetStatus::Publishing->value, 'claimed_at' => now()]);

        if ($claimed !== 1) {
            return null;
        }

        $target->refresh();

        return $this->execute($target);
    }

    private function execute(PostTarget $target): AttemptOutcome
    {
        $account = $target->account;
        $secret = $account->access_token;

        try {
            $platform = $this->platforms->get($account->platform);
            $credentials = $this->accounts->credentials($account);
            $payload = $this->payload($target);

            if ($platform instanceof StepwisePublisher && $platform->usesSteps($payload)) {
                return $this->step($target, $platform, $credentials, $payload, $secret);
            }

            $published = $platform->publish($credentials, $payload);
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

        $this->markPublished($target, $published);

        return AttemptOutcome::Success;
    }

    /**
     * One step of a stepwise publish. A transient failure keeps the saved session and tries again later
     * (bounded by `social.publishing.step_failures_max`); everything else is a normal failure.
     */
    private function step(PostTarget $target, StepwisePublisher $platform, AccountCredentials $credentials, PublishPayload $payload, ?string $secret): AttemptOutcome
    {
        $meta = (array) ($target->remote_meta ?? []);
        $session = new PublishSession(
            $target->remote_id, $target->remote_state ?? 'uploading', (int) $target->upload_offset, $meta, $target->external_id,
        );

        try {
            $progress = $platform->advance($credentials, $payload, $session, $this->deadline());
        } catch (PublishFailed $failure) {
            if (! $failure->retryable || $failure->reauthorize) {
                throw $failure;
            }

            $failures = (int) ($meta['failures'] ?? 0) + 1;

            if ($failures >= max(1, (int) config('social.publishing.step_failures_max', 6))) {
                throw PublishFailed::rejected($failure->getMessage(), $failure->errorCode);
            }

            return $this->deferStep($target, $meta, $failures, $failure, $secret);
        }

        if ($progress->published !== null) {
            $this->markPublished($target, $progress->published);

            return AttemptOutcome::Success;
        }

        $changed = $target->remote_state !== $progress->state;

        DB::transaction(function () use ($target, $progress, $changed): void {
            $target->fill([
                'status' => TargetStatus::Processing,
                'remote_id' => $progress->remoteId ?? $target->remote_id,
                'remote_state' => $progress->state,
                'upload_offset' => $progress->offset,
                'remote_meta' => $progress->meta === [] ? null : $progress->meta,
                // The platform's id is stored the moment the final call was accepted: it is what stops a repeat.
                'external_id' => $progress->externalId !== null ? Str::limit($progress->externalId, 190, '') : $target->external_id,
                'remote_started_at' => $target->remote_started_at ?? now(),
                'next_attempt_at' => now()->addSeconds(max(0, $progress->retryAfterSeconds)),
                'claimed_at' => null,
                'last_error' => null,
                'last_error_code' => null,
            ])->save();

            if ($changed && $progress->state === 'processing') {
                $this->log($target, AttemptOutcome::Deferred, 'processing', __('The media was uploaded. The platform is processing it.'));
            }

            $this->rollup->refresh($target->post_id);
        });

        if ($changed && $progress->state === 'processing') {
            RateLimiter::hit('social:account:'.$target->account_id, 3600);
            RateLimiter::hit('social:publish', 60);
        }

        return AttemptOutcome::Deferred;
    }

    /** @param array<string, mixed> $meta */
    private function deferStep(PostTarget $target, array $meta, int $failures, PublishFailed $failure, ?string $secret): AttemptOutcome
    {
        $message = Str::limit($this->redact($failure->getMessage(), $secret), 480, '');
        $backoff = array_values(array_map('intval', (array) config('social.publishing.backoff_seconds', [60, 300, 1800])));
        $delay = $failure->retryAfterSeconds ?? ($backoff[min($failures, max(1, count($backoff))) - 1] ?? 60);

        return DB::transaction(function () use ($target, $meta, $failures, $failure, $message, $delay): AttemptOutcome {
            $target->fill([
                'status' => TargetStatus::Processing,
                'remote_state' => $target->remote_state ?? 'uploading',
                'remote_started_at' => $target->remote_started_at ?? now(),
                'remote_meta' => [...$meta, 'failures' => $failures],
                'next_attempt_at' => now()->addSeconds(max(1, $delay)),
                'claimed_at' => null,
                'last_error' => $message,
                'last_error_code' => $failure->errorCode,
            ])->save();
            $this->log($target, AttemptOutcome::Retry, $failure->errorCode, $message);
            $this->rollup->refresh($target->post_id);

            return AttemptOutcome::Retry;
        });
    }

    private function markPublished(PostTarget $target, PublishedPost $published): void
    {
        // A target that went through `processing` was already counted when its upload was accepted.
        if ($target->remote_state !== 'processing') {
            RateLimiter::hit('social:account:'.$target->account_id, 3600);
            RateLimiter::hit('social:publish', 60);
        }

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
                'remote_id' => null,
                'remote_state' => null,
                'upload_offset' => 0,
                'remote_meta' => null,
                'remote_started_at' => null,
            ])->save();
            $target->account->forceFill(['last_used_at' => now(), 'last_error' => null])->save();
            $this->log($target, AttemptOutcome::Success);
            $this->rollup->refresh($target->post_id);
        });
    }

    private function deadline(): float
    {
        $now = microtime(true);

        return $this->deadline > $now ? $this->deadline : $now + max(0.0, (float) config('social.publishing.run_budget_seconds', 35));
    }

    /**
     * Media the platform has not finished within `processing_timeout_minutes`. The platform may still
     * publish it later, so nothing is retried: a person has to look.
     */
    public function flagStaleProcessing(): int
    {
        $cutoff = now()->subMinutes((int) config('social.publishing.processing_timeout_minutes', 180));
        $stale = PostTarget::query()
            ->where('status', TargetStatus::Processing->value)
            ->whereNotNull('remote_started_at')
            ->where('remote_started_at', '<', $cutoff)
            ->pluck('id');
        $flagged = 0;

        foreach ($stale as $id) {
            $done = DB::transaction(function () use ($id): bool {
                $target = PostTarget::query()->lockForUpdate()->find($id);

                if ($target === null || $target->status !== TargetStatus::Processing) {
                    return false;
                }

                $message = __('The platform did not finish processing the media in time. Check the account before trying again.');
                $target->fill([
                    'status' => TargetStatus::Failed,
                    'last_error' => $message,
                    'last_error_code' => 'processing_timeout',
                    'needs_manual_check' => true,
                    'next_attempt_at' => null,
                    'claimed_at' => null,
                    'external_id' => null,
                    ...$this->clearedRemote(),
                ])->save();
                $this->log($target, AttemptOutcome::Failed, 'processing_timeout', $message);
                $this->rollup->refresh($target->post_id);

                return true;
            });

            $flagged += $done ? 1 : 0;
        }

        return $flagged;
    }

    /** @return array<string, mixed> */
    private function clearedRemote(): array
    {
        return ['remote_id' => null, 'remote_state' => null, 'upload_offset' => 0, 'remote_meta' => null, 'remote_started_at' => null];
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
                    // A target whose media is already with the platform resumes as processing, never as a fresh publish.
                    'status' => $target->external_id !== null ? TargetStatus::Processing : TargetStatus::Pending,
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
                'external_id' => null,
                ...$this->clearedRemote(),
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
                sizeBytes: $item->size_bytes,
                width: $item->width,
                height: $item->height,
                durationMs: $item->duration_ms,
                ranger: fn (int $offset, int $length): string => $this->readRange($item->path, $offset, $length),
            );
        })->values()->all();

        return new PublishPayload(
            text: $post->composedBody($target->body_override),
            linkUrl: $post->link_url,
            media: $media,
            idempotencyKey: $target->idempotency_key,
            postType: $post->post_type ?? PostType::Post,
        );
    }

    /** A slice of a stored file, read through a stream so a large video never sits in memory whole. */
    private function readRange(string $path, int $offset, int $length): string
    {
        $stream = $this->storage->disk()->readStream($this->storage->path($path));

        if (! is_resource($stream)) {
            throw PublishFailed::rejected('A media file is missing from storage.', 'media_missing');
        }

        try {
            $data = stream_get_contents($stream, $length, $offset);
        } finally {
            fclose($stream);
        }

        return is_string($data) ? $data : throw PublishFailed::rejected('A media file is missing from storage.', 'media_missing');
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
