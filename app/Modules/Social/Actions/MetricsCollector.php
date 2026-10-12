<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Platforms\Exceptions\PlatformUnavailable;
use App\Modules\Social\Platforms\Exceptions\PublishFailed;
use App\Modules\Social\Platforms\SocialPlatforms;
use Throwable;

/**
 * Reads engagement figures from the platforms for recently published posts. Only what a
 * platform returns is stored; a platform without the analytics capability is never asked, and a
 * failed read stores nothing.
 */
final class MetricsCollector
{
    public function __construct(
        private readonly SocialPlatforms $platforms,
        private readonly AccountManager $accounts,
    ) {}

    /** @return int how many targets received a new snapshot */
    public function run(): int
    {
        $window = now()->subDays((int) config('social.metrics.window_days', 30));
        $stale = now()->subHours((int) config('social.metrics.refresh_hours', 6));

        $targets = PostTarget::query()
            ->with('account')
            ->where('status', TargetStatus::Published->value)
            ->whereNotNull('external_id')
            ->where('published_at', '>=', $window)
            ->where(fn ($query) => $query->whereNull('metrics_synced_at')->orWhere('metrics_synced_at', '<', $stale))
            ->whereHas('account', fn ($query) => $query->where('status', AccountStatus::Connected->value))
            ->orderBy('metrics_synced_at')
            ->limit((int) config('social.metrics.batch', 50))
            ->get();

        $stored = 0;

        foreach ($targets as $target) {
            $platform = $this->platforms->find($target->account->platform);

            if ($platform === null || ! $platform->availability()->available || ! $platform->capabilities()->analytics) {
                continue;
            }

            try {
                $snapshot = $platform->metrics($this->accounts->credentials($target->account), (string) $target->external_id);
            } catch (PublishFailed|PlatformUnavailable) {
                $snapshot = null;
            } catch (Throwable) {
                $snapshot = null;
            }

            $target->forceFill(['metrics_synced_at' => now()])->save();

            if ($snapshot === null || $snapshot->isEmpty()) {
                continue;
            }

            PostMetric::query()->create([
                'target_id' => $target->id,
                'captured_at' => now(),
                'impressions' => $snapshot->impressions,
                'reach' => $snapshot->reach,
                'likes' => $snapshot->likes,
                'comments' => $snapshot->comments,
                'shares' => $snapshot->shares,
                'clicks' => $snapshot->clicks,
            ]);
            $stored++;
        }

        return $stored;
    }
}
