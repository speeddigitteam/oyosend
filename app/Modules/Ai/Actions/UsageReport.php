<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Core\Billing\Models\UsageRecord;
use App\Core\Billing\UsageLimits;
use Illuminate\Support\Carbon;

/**
 * Token usage of the active workspace for the usage page and the dashboard widget.
 */
final class UsageReport
{
    public function __construct(private readonly UsageLimits $limits) {}

    /**
     * @return array{limit: int|null, used: int, remaining: int|null}
     */
    public function allowance(): array
    {
        return [
            'limit' => $this->limits->limit(AiGateway::TOKENS),
            'used' => $this->limits->used(AiGateway::TOKENS),
            'remaining' => $this->limits->remaining(AiGateway::TOKENS),
        ];
    }

    /**
     * Last 30 days by feature and by day (UTC dates), from the idempotent usage records.
     *
     * @return array{byFeature: list<array{feature: string, tokens: int, requests: int}>, byDay: list<array{date: string, tokens: int}>}
     */
    public function breakdown(): array
    {
        $since = Carbon::now()->subDays(30)->startOfDay();

        $records = UsageRecord::query()
            ->where('meter', AiGateway::TOKENS)
            ->where('occurred_at', '>=', $since)
            ->get(['quantity', 'meta', 'occurred_at']);

        $features = [];
        $days = [];

        foreach ($records as $record) {
            $feature = (string) ($record->meta['feature'] ?? 'unknown');
            $features[$feature]['tokens'] = ($features[$feature]['tokens'] ?? 0) + $record->quantity;
            $features[$feature]['requests'] = ($features[$feature]['requests'] ?? 0) + 1;

            $date = $record->occurred_at->toDateString();
            $days[$date] = ($days[$date] ?? 0) + $record->quantity;
        }

        ksort($days);

        return [
            'byFeature' => array_map(
                static fn (string $feature, array $row): array => ['feature' => $feature, 'tokens' => $row['tokens'], 'requests' => $row['requests']],
                array_keys($features),
                $features,
            ),
            'byDay' => array_map(
                static fn (string $date, int $tokens): array => ['date' => $date, 'tokens' => $tokens],
                array_keys($days),
                $days,
            ),
        ];
    }
}
