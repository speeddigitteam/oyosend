<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;

/**
 * Reports. Publishing counts come from this application's own records. Engagement figures come
 * only from {@see PostMetric} rows a platform returned: a figure no platform returned is `null`
 * ("not reported"), never zero, and a platform without analytics contributes nothing.
 */
final class SocialAnalytics
{
    private const array FIGURES = ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'];

    public function __construct(private readonly SocialPlatforms $platforms) {}

    /**
     * @return array{
     *     from: string, to: string,
     *     publishing: array{published: int, failed: int, pending: int, byPlatform: list<array{platform: string, label: string, published: int, failed: int}>},
     *     engagement: array{reportedPosts: int, capturedAt: string|null, totals: array<string, int|null>, byPlatform: list<array{platform: string, label: string, posts: int, totals: array<string, int|null>}>, topPosts: list<array<string, mixed>>},
     *     platformsWithAnalytics: list<string>
     * }
     */
    public function overview(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $targets = PostTarget::query()
            ->with(['account:id,platform,name', 'post:id,title,body,published_at'])
            ->where(function ($query) use ($from, $to): void {
                $query->whereBetween('published_at', [$from, $to])
                    ->orWhere(fn ($failed) => $failed->where('status', TargetStatus::Failed->value)->whereBetween('updated_at', [$from, $to]));
            })
            ->limit(5000)
            ->get();

        $latest = [];

        foreach (PostMetric::query()->whereIn('target_id', $targets->pluck('id'))->orderByDesc('captured_at')->get() as $metric) {
            $latest[$metric->target_id] ??= $metric;
        }

        $publishing = ['published' => 0, 'failed' => 0, 'pending' => 0, 'byPlatform' => []];
        $platforms = [];
        $totals = array_fill_keys(self::FIGURES, null);
        $capturedAt = null;
        $reportedPosts = 0;
        $top = [];

        foreach ($targets as $target) {
            $key = $target->account->platform;
            $label = $this->platforms->find($key)?->label() ?? $key;
            $platforms[$key] ??= ['platform' => $key, 'label' => $label, 'published' => 0, 'failed' => 0, 'posts' => 0, 'totals' => array_fill_keys(self::FIGURES, null)];

            if ($target->status === TargetStatus::Published) {
                $publishing['published']++;
                $platforms[$key]['published']++;
            } elseif ($target->status === TargetStatus::Failed) {
                $publishing['failed']++;
                $platforms[$key]['failed']++;
            } else {
                $publishing['pending']++;
            }

            $metric = $latest[$target->id] ?? null;

            if ($metric === null) {
                continue;
            }

            $reportedPosts++;
            $platforms[$key]['posts']++;
            $capturedAt = $capturedAt === null || $metric->captured_at->gt($capturedAt) ? $metric->captured_at : $capturedAt;

            foreach (self::FIGURES as $figure) {
                if ($metric->{$figure} !== null) {
                    $totals[$figure] = ($totals[$figure] ?? 0) + $metric->{$figure};
                    $platforms[$key]['totals'][$figure] = ($platforms[$key]['totals'][$figure] ?? 0) + $metric->{$figure};
                }
            }

            $top[] = [
                'targetId' => $target->id,
                'postId' => $target->post_id,
                'title' => $target->post->title ?? mb_strimwidth((string) $target->post->body, 0, 80, '…'),
                'platform' => $key,
                'platformLabel' => $label,
                'accountName' => $target->account->name,
                'url' => $target->external_url,
                'capturedAt' => $metric->captured_at->toIso8601String(),
                'engagement' => ($metric->likes ?? 0) + ($metric->comments ?? 0) + ($metric->shares ?? 0),
                'figures' => array_combine(self::FIGURES, array_map(fn (string $f): ?int => $metric->{$f}, self::FIGURES)),
            ];
        }

        usort($top, static fn (array $a, array $b): int => $b['engagement'] <=> $a['engagement']);

        return [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'publishing' => [
                'published' => $publishing['published'],
                'failed' => $publishing['failed'],
                'pending' => $publishing['pending'],
                'byPlatform' => array_values(array_map(static fn (array $p): array => [
                    'platform' => $p['platform'], 'label' => $p['label'], 'published' => $p['published'], 'failed' => $p['failed'],
                ], $platforms)),
            ],
            'engagement' => [
                'reportedPosts' => $reportedPosts,
                'capturedAt' => $capturedAt?->toIso8601String(),
                'totals' => $totals,
                'byPlatform' => array_values(array_map(static fn (array $p): array => [
                    'platform' => $p['platform'], 'label' => $p['label'], 'posts' => $p['posts'], 'totals' => $p['totals'],
                ], array_filter($platforms, static fn (array $p): bool => $p['posts'] > 0))),
                'topPosts' => array_slice($top, 0, 10),
            ],
            'platformsWithAnalytics' => array_keys(array_filter(
                $this->platforms->all(),
                static fn ($platform): bool => $platform->availability()->available && $platform->capabilities()->analytics,
            )),
        ];
    }
}
