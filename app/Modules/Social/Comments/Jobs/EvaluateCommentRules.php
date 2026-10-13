<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Jobs;

use App\Core\Tenancy\Contracts\RequiresTenantContext;
use App\Modules\Social\Comments\Automation\RuleEngine;
use App\Modules\Social\Comments\Models\AudienceComment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs the keyword rules on one new comment. Carries only the comment id. Work held back by quiet hours or a cap
 * is looked at again later by this same job; every action is claimed per rule and comment first, so a repeated or
 * overlapping run never answers twice.
 */
final class EvaluateCommentRules implements RequiresTenantContext, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** Seconds; under the background supervisor's worker timeout. */
    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $commentId, public readonly int $deferrals = 0)
    {
        $this->onQueue('automation');
    }

    public function handle(RuleEngine $engine): void
    {
        $comment = AudienceComment::query()->find($this->commentId);

        if ($comment === null) {
            return;
        }

        $deferral = $engine->process($comment);

        if ($deferral === null) {
            return;
        }

        $used = $this->deferrals + ($deferral->counts ? 1 : 0);

        if ($used > (int) config('social_comments.automation.max_deferrals', 6)) {
            return;
        }

        self::dispatch($this->commentId, $used)->delay($deferral->until);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['app:social', 'comment-rules'];
    }
}
