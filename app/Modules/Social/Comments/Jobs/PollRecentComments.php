<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Comments\Actions\CommentSources;
use App\Modules\Social\Comments\CommentGateway;
use App\Modules\Social\Comments\Data\CommentActionFailed;
use App\Modules\Social\Comments\Ingest\CommentIngestor;
use App\Modules\Social\Comments\Models\CommentSource;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\PostTarget;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * The fallback for missed webhooks: every minute (through the entitlement-gated runner) it reads the recent
 * comments of a few Pages whose turn has come. Bounded (Pages per run, posts per Page, comments per post, a lookback
 * window), spaced (a minimum interval, longer after failures) and rate-limit aware (a throttled Page backs off).
 * Ingestion is deduplicated by the platform's comment id, so overlapping with the webhook is harmless.
 */
final class PollRecentComments implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    /** Seconds; under the background supervisor's worker timeout. */
    public int $timeout = 240;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue('automation');
    }

    public function handle(CommentGateway $gateway, CommentIngestor $ingestor, CommentSources $sources, AccountManager $accounts): void
    {
        $limit = max(1, (int) config('social_comments.poll.accounts_per_run', 5));
        $done = 0;

        $candidates = CommentSource::query()->with('account')->where('is_enabled', true)
            ->orderByRaw('last_polled_at is not null')->orderBy('last_polled_at')->limit($limit * 4)->get();

        foreach ($candidates as $source) {
            if ($done >= $limit) {
                break;
            }

            if (! $this->due($source) || $source->account->status !== AccountStatus::Connected || ! $sources->owns($source->account)) {
                continue;
            }

            $done++;
            $this->poll($source, $gateway, $ingestor, $accounts);
        }
    }

    private function due(CommentSource $source): bool
    {
        if ($source->last_polled_at === null) {
            return true;
        }

        $interval = max(1, (int) config('social_comments.poll.interval_minutes', 10));

        if ($source->webhook_state === CommentSource::SUBSCRIBED && $source->last_event_at !== null && $source->last_event_at->gt(now()->subDay())) {
            $interval *= max(1, (int) config('social_comments.poll.subscribed_multiplier', 3));
        }

        if ($source->consecutive_failures > 0) {
            $interval = min((int) config('social_comments.poll.max_backoff_minutes', 360), $interval * (2 ** min(6, $source->consecutive_failures)));
        }

        return $source->last_polled_at->lte(now()->subMinutes($interval));
    }

    private function poll(CommentSource $source, CommentGateway $gateway, CommentIngestor $ingestor, AccountManager $accounts): void
    {
        $account = $source->account;
        $error = null;

        try {
            $provider = $gateway->provider($account);
            $credentials = $gateway->credentials($account);

            $lookback = CarbonImmutable::now()->subDays(max(1, (int) config('social_comments.poll.lookback_days', 7)));
            $since = $source->last_polled_at === null ? $lookback : CarbonImmutable::instance($source->last_polled_at)->subMinutes(5);
            $since = $since->lt($lookback) ? $lookback : $since;
            $postLimit = max(1, (int) config('social_comments.poll.posts_per_account', 10));

            $postIds = $provider->recentPostIds($credentials, $postLimit);

            $own = PostTarget::query()->where('account_id', $account->id)->whereNotNull('external_id')
                ->where('published_at', '>=', $lookback)->orderByDesc('published_at')->limit($postLimit)->pluck('external_id')->all();

            foreach (array_slice(array_values(array_unique([...$postIds, ...array_map('strval', $own)])), 0, $postLimit * 2) as $postId) {
                $page = $provider->recent($credentials, $postId, $since, max(1, (int) config('social_comments.poll.comments_per_post', 50)));

                foreach ($page->comments as $remote) {
                    try {
                        $ingestor->store($account, $remote, 'poll');
                    } catch (Throwable $exception) {
                        // One bad comment must not stop the rest; log the class only, never the text.
                        report($exception);
                    }
                }

                if (! $page->ok()) {
                    if ($page->status === CommentActionFailed::TOKEN) {
                        $accounts->markNeedsReauthorization($account, __('Facebook no longer accepts this Page connection. Connect the Page again to continue.'));
                    }

                    $error = $page->errorCode ?? $page->status;

                    break;
                }
            }
        } catch (CommentActionFailed $failure) {
            if ($failure->kind === CommentActionFailed::TOKEN) {
                $accounts->markNeedsReauthorization($account, $failure->getMessage());
            }

            $error = $failure->errorCode;
        }

        $source->forceFill([
            'last_polled_at' => now(),
            'last_error_code' => $error,
            'consecutive_failures' => $error === null ? 0 : min(100, $source->consecutive_failures + 1),
        ])->save();
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'schedule:social.comments_poll'];
    }
}
