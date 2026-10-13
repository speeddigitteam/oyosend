<?php

declare(strict_types=1);

namespace App\Modules\Social\Ai;

use App\Core\Ai\Contracts\TextAssistant;
use App\Core\Ai\Text\Sentiment;
use App\Core\Ai\Text\TextKind;
use App\Core\Ai\Text\TextTask;
use App\Modules\Social\Actions\SocialSettings;
use App\Modules\Social\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Sentiment of audience comments, through the core TextAssistant contract (ADR 0024).
 *
 * Reusable by the audience-comments module without importing anything of it: pass the account id and the
 * comments as `external comment id => text`. See docs/SOCIAL.md "AI assist and sentiment".
 *
 *  - Bounded: at most MAX_BATCH comments per `analyse()` call, QUEUE_CHUNK per queued job.
 *  - Idempotent: the metering purpose is derived from account + comment id + text hash, and a comment whose
 *    stored result has the same text hash is not analysed again.
 *  - Capped: `social_settings.ai_daily_cap` comments per UTC day per workspace (counted from stored results).
 *  - Honest: any assistant state other than `ok` stops the batch and is returned; nothing is guessed.
 *  - Text only: the result is a stored label and score. It never changes, hides, deletes or answers a comment.
 */
final class CommentSentiment
{
    public const int MAX_BATCH = 50;

    public const int QUEUE_CHUNK = 25;

    public function __construct(
        private readonly TextAssistant $assistant,
        private readonly SocialSettings $settings,
    ) {}

    /**
     * Analyse comments now (a worker or a test; interactive requests should use queue()).
     *
     * @param  array<string, string>  $comments  external comment id => comment text
     * @param  array<string, CarbonInterface|string|null>  $commentedAt  external comment id => when it was written (optional)
     */
    public function analyse(string $accountId, array $comments, array $commentedAt = [], ?string $actorMembershipId = null): BatchOutcome
    {
        $account = SocialAccount::query()->findOrFail($accountId);
        $comments = array_slice($comments, 0, self::MAX_BATCH, true);

        $analysed = $unchanged = $skipped = $pending = 0;
        $stopped = null;
        $retryable = false;
        $capReached = false;
        $remaining = $this->remainingToday();

        foreach ($comments as $externalId => $text) {
            $externalId = (string) $externalId;
            $text = trim((string) $text);

            if ($externalId === '' || mb_strlen($externalId) > 191 || $text === '') {
                $skipped++;

                continue;
            }

            $hash = hash('sha256', $text);
            $existing = SentimentRecord::query()
                ->where('account_id', $account->id)
                ->where('external_comment_id', $externalId)
                ->first();

            if ($existing !== null && hash_equals($existing->text_hash, $hash)) {
                $unchanged++;

                continue;
            }

            if ($stopped !== null || $capReached) {
                $pending++;

                continue;
            }

            if ($remaining <= 0) {
                $capReached = true;
                $pending++;

                continue;
            }

            $result = $this->assistant->generate(new TextTask(
                kind: TextKind::Sentiment,
                input: $text,
                purpose: 'social:sent:'.substr(hash('sha256', $account->id.'|'.$externalId.'|'.$hash), 0, 48),
                platform: preg_match('/^[a-z0-9_]{1,32}$/', $account->platform) === 1 ? $account->platform : null,
                actorMembershipId: $actorMembershipId,
            ));

            if (! $result->ok()) {
                $stopped = $result->state;
                $retryable = $result->retryable;
                $pending++;

                continue;
            }

            $this->store(
                $account->id,
                $externalId,
                $hash,
                $result->sentiment ?? Sentiment::Unknown,
                $result->scoreBasisPoints,
                $result->language,
                $result->promptVersion,
                $commentedAt[$externalId] ?? null,
            );
            $analysed++;
            $remaining--;
        }

        return new BatchOutcome($analysed, $unchanged, $skipped, $pending, $capReached, $stopped, $retryable);
    }

    /**
     * Queue comments for analysis in bounded jobs on the `ai` queue. Returns how many jobs were dispatched.
     *
     * @param  array<string, string>  $comments  external comment id => comment text
     * @param  array<string, CarbonInterface|string|null>  $commentedAt
     */
    public function queue(string $accountId, array $comments, array $commentedAt = [], ?string $actorMembershipId = null): int
    {
        SocialAccount::query()->findOrFail($accountId);
        $jobs = 0;

        foreach (array_chunk($comments, self::QUEUE_CHUNK, true) as $chunk) {
            $times = [];

            foreach (array_intersect_key($commentedAt, $chunk) as $id => $value) {
                $times[(string) $id] = $value instanceof CarbonInterface ? $value->toIso8601String() : $value;
            }

            $jobs++;
            dispatch(new AnalyseCommentsJob($accountId, $chunk, $times, $actorMembershipId));
        }

        return $jobs;
    }

    /** Comments analysed so far today (UTC), counted from stored results. */
    public function usedToday(): int
    {
        return SentimentRecord::query()->where('analysed_at', '>=', Carbon::now('UTC')->startOfDay())->count();
    }

    public function dailyCap(): int
    {
        return (int) $this->settings->current()->ai_daily_cap;
    }

    public function remainingToday(): int
    {
        return max(0, $this->dailyCap() - $this->usedToday());
    }

    /**
     * The sentiment mix of the analysed comments in a period (by the comment's own time, else by when it was
     * analysed), per account and in total. Unknown results are counted separately and never averaged.
     *
     * @return array<string, mixed>
     */
    public function summary(CarbonInterface $from, CarbonInterface $to, ?string $accountId = null): array
    {
        $rows = SentimentRecord::query()
            ->when($accountId !== null, fn ($query) => $query->where('account_id', $accountId))
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('commented_at', [$from, $to])
                    ->orWhere(function ($inner) use ($from, $to): void {
                        $inner->whereNull('commented_at')->whereBetween('analysed_at', [$from, $to]);
                    });
            })
            ->get(['account_id', 'sentiment', 'score_basis_points']);

        $names = SocialAccount::query()
            ->whereIn('id', $rows->pluck('account_id')->unique()->all())
            ->get(['id', 'name', 'platform'])
            ->keyBy('id');

        $accounts = [];
        $total = $this->emptyMix();
        $sum = 0;
        $scored = 0;

        foreach ($rows as $row) {
            $key = $row->account_id;
            $accounts[$key] ??= ['mix' => $this->emptyMix(), 'sum' => 0, 'scored' => 0];
            $accounts[$key]['mix'][$row->sentiment->value]++;
            $total[$row->sentiment->value]++;

            if ($row->sentiment !== Sentiment::Unknown && $row->score_basis_points !== null) {
                $accounts[$key]['sum'] += $row->score_basis_points;
                $accounts[$key]['scored']++;
                $sum += $row->score_basis_points;
                $scored++;
            }
        }

        $list = [];

        foreach ($accounts as $id => $data) {
            $account = $names->get($id);
            $list[] = [
                'accountId' => (string) $id,
                'name' => $account === null ? '' : (string) $account->name,
                'platform' => $account === null ? '' : (string) $account->platform,
                'total' => array_sum($data['mix']),
                'mix' => $data['mix'],
                'averageScoreBasisPoints' => $data['scored'] > 0 ? (int) round($data['sum'] / $data['scored']) : null,
            ];
        }

        usort($list, static fn (array $a, array $b): int => $b['total'] <=> $a['total'] ?: strcmp($a['name'], $b['name']));

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'total' => array_sum($total),
            'mix' => $total,
            'averageScoreBasisPoints' => $scored > 0 ? (int) round($sum / $scored) : null,
            'accounts' => $list,
            'dailyCap' => $this->dailyCap(),
            'usedToday' => $this->usedToday(),
        ];
    }

    /** @return array<string, int> */
    private function emptyMix(): array
    {
        return array_fill_keys(array_map(static fn (Sentiment $case): string => $case->value, Sentiment::cases()), 0);
    }

    private function store(
        string $accountId,
        string $externalId,
        string $hash,
        Sentiment $sentiment,
        ?int $score,
        ?string $language,
        string $promptVersion,
        CarbonInterface|string|null $commentedAt,
    ): void {
        $values = [
            'text_hash' => $hash,
            'sentiment' => $sentiment,
            'score_basis_points' => $score,
            'language' => $language !== null ? mb_substr($language, 0, 12) : null,
            'prompt_version' => mb_substr($promptVersion, 0, 40),
            'commented_at' => $commentedAt !== null ? Carbon::parse($commentedAt) : null,
            'analysed_at' => Carbon::now('UTC'),
        ];
        $keys = ['account_id' => $accountId, 'external_comment_id' => $externalId];

        try {
            SentimentRecord::query()->updateOrCreate($keys, $values);
        } catch (UniqueConstraintViolationException) {
            // A concurrent worker stored it first: this write is the later, equivalent result.
            SentimentRecord::query()->where($keys)->update([...$values, 'sentiment' => $sentiment->value]);
        }
    }
}
