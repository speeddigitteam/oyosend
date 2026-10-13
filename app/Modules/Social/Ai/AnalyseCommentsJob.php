<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Scores one bounded chunk of audience comments. One chunk per account runs at a time (bounded concurrency),
 * a rate-limited answer is retried later, and an unconfigured, disabled or exhausted AI ends the job quietly:
 * the comments stay unscored until a later run.
 */
final class AnalyseCommentsJob implements RequiresTenantContext, ShouldBeEncrypted, ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** Seconds; under the `ai` supervisor worker timeout (300). */
    public int $timeout = 240;

    /** @var list<int> Seconds between attempts. */
    public array $backoff = [60, 180, 600];

    /**
     * @param  array<string, string>  $comments  external comment id => text (at most CommentSentiment::QUEUE_CHUNK)
     * @param  array<string, string|null>  $commentedAt
     */
    public function __construct(
        public readonly string $accountId,
        public readonly array $comments,
        public readonly array $commentedAt = [],
        public readonly ?string $actorMembershipId = null,
    ) {
        $this->onQueue('ai');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('social-sentiment:'.$this->accountId))->releaseAfter(30)->expireAfter($this->timeout + 30)];
    }

    public function handle(CommentSentiment $sentiment): void
    {
        $outcome = $sentiment->analyse($this->accountId, $this->comments, $this->commentedAt, $this->actorMembershipId);

        if ($outcome->stopped !== null && $outcome->retryable && $this->attempts() < $this->tries) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'social:sentiment'];
    }
}
