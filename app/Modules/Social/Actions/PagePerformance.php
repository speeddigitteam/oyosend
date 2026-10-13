<?php

declare(strict_types=1);

namespace App\Modules\Social\Actions;

use App\Core\Identity\Models\User;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Enums\MediaKind;
use App\Modules\Social\Enums\TargetStatus;
use App\Modules\Social\Models\AccountDiagnostic;
use App\Modules\Social\Models\Campaign;
use App\Modules\Social\Models\PageInsight;
use App\Modules\Social\Models\PostMedia;
use App\Modules\Social\Models\PostMetric;
use App\Modules\Social\Models\PostTarget;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\PageInsightsProviders;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The performance dashboard: Page-level insights per account per day (from
 * {@see PageInsight}, collected by {@see PageInsightsCollector}) and what our own published
 * posts achieved (from {@see PostMetric}).
 *
 * Honesty rules: a figure nobody reported is `null`, never zero; a sum covers only the days or
 * accounts that reported and says how many; a change against the previous period is shown
 * only when both periods report the same number of days; best posting times come from our own
 * posts' latest figures, in the workspace timezone, and say how small the sample is.
 */
final class PagePerformance
{
    /** @var list<string> The KPI figures in display order. */
    public const array KPIS = [
        'followers', 'new_followers', 'unfollows', 'impressions', 'reach', 'engaged_users',
        'post_engagements', 'page_views', 'reactions', 'video_views', 'fans',
    ];

    /** Figures that are a level at a point in time (the last value counts), not a flow (a sum). */
    private const array LEVELS = ['followers', 'fans'];

    public const array PERIODS = [7, 28, 90];

    public const int MAX_PAGES = 10;

    private const int POST_WINDOW_DAYS = 90;

    private const array POST_FIGURES = ['impressions', 'reach', 'likes', 'comments', 'shares', 'clicks'];

    public function __construct(
        private readonly SocialSettings $settings,
        private readonly SocialPlatforms $platforms,
        private readonly PageInsightsProviders $providers,
        private readonly AccountAllowList $allowList,
    ) {}

    /**
     * The accounts the dashboard can show: connected-or-not, on a platform with an insights
     * provider, and allowed for analytics to `$user`.
     *
     * @return list<SocialAccount>
     */
    public function accounts(User $user): array
    {
        $keys = $this->providers->platforms();

        if ($keys === []) {
            return [];
        }

        $accounts = SocialAccount::query()
            ->where('status', '!=', AccountStatus::Disconnected->value)
            ->whereIn('platform', $keys)
            ->orderBy('platform')->orderBy('name')
            ->get();
        $allowed = $this->allowList->allowedIds($user, AccountAllowList::ANALYTICS, $accounts->pluck('id')->all());

        return $accounts->filter(static fn (SocialAccount $account): bool => in_array($account->id, $allowed, true))->values()->all();
    }

    /**
     * @param  list<string>|null  $pageIds  null selects every allowed account
     * @return array<string, mixed>
     */
    public function dashboard(User $user, int $days = 28, ?array $pageIds = null, ?CarbonImmutable $now = null): array
    {
        $days = in_array($days, self::PERIODS, true) ? $days : 28;
        $timezone = $this->settings->timezone();
        $now ??= CarbonImmutable::now();
        $today = $now->setTimezone($timezone)->startOfDay();
        $from = $today->subDays($days - 1);
        $previousFrom = $from->subDays($days);
        $previousTo = $from->subDay();
        $dates = $this->dates($from, $today);
        $previousDates = $this->dates($previousFrom, $previousTo);

        $available = $this->accounts($user);
        $everyAccountExists = SocialAccount::query()->where('status', '!=', AccountStatus::Disconnected->value)
            ->whereIn('platform', $this->providers->platforms() ?: ['none'])->exists();
        $selected = $pageIds === null
            ? $available
            : array_values(array_filter($available, static fn (SocialAccount $account): bool => in_array($account->id, $pageIds, true)));
        $selected = array_slice($selected, 0, self::MAX_PAGES);
        $selectedIds = array_map(static fn (SocialAccount $account): string => $account->id, $selected);

        $diagnostics = AccountDiagnostic::query()->whereIn('account_id', $selectedIds)->get()->keyBy('account_id');
        $rows = $this->rows($selectedIds, $previousFrom->format('Y-m-d'), $today->format('Y-m-d'));

        $currentSummary = $this->summarise($rows, $selectedIds, $dates);
        $previousSummary = $this->summarise($rows, $selectedIds, $previousDates);

        $kpis = [];

        foreach (self::KPIS as $figure) {
            $current = $currentSummary[$figure];
            $previous = $previousSummary[$figure];
            $isLevel = in_array($figure, self::LEVELS, true);
            $comparable = $current['value'] !== null && $previous['value'] !== null
                && ($isLevel || $current['days'] === $previous['days']);
            $delta = $comparable ? $current['value'] - $previous['value'] : null;

            $kpis[] = [
                'figure' => $figure,
                'level' => $isLevel,
                'value' => $current['value'],
                'previous' => $previous['value'],
                'delta' => $delta,
                'deltaPct' => $delta !== null && $previous['value'] > 0 ? round($delta / $previous['value'] * 100, 1) : null,
                'days' => $current['days'],
                'previousDays' => $previous['days'],
                'expectedDays' => $days,
                'asOf' => $current['asOf'],
            ];
        }

        $trend = [];

        foreach (self::KPIS as $figure) {
            $perAccount = [];
            $total = [];

            foreach ($dates as $date) {
                $sum = null;

                foreach ($selectedIds as $id) {
                    $value = $rows[$id][$date][$figure] ?? null;
                    $perAccount[$id][] = $value;

                    if ($value !== null) {
                        $sum = ($sum ?? 0) + $value;
                    }
                }

                $total[] = $sum;
            }

            $trend[$figure] = [
                'total' => $total,
                'accounts' => array_map(static fn (SocialAccount $account): array => [
                    'id' => $account->id,
                    'name' => $account->name,
                    'values' => $perAccount[$account->id] ?? array_fill(0, count($dates), null),
                ], $selected),
            ];
        }

        $comparison = [];

        foreach ($selected as $account) {
            $mine = $this->summarise($rows, [$account->id], $dates);
            $diagnostic = $diagnostics->get($account->id);
            $comparison[] = [
                'id' => $account->id,
                'name' => $account->name,
                'platform' => $account->platform,
                'platformLabel' => $this->platforms->find($account->platform)?->label() ?? $account->platform,
                'status' => $account->status->value,
                'figures' => array_map(static fn (array $figure): ?int => $figure['value'], $mine),
                'daysReported' => $this->daysWithData($rows, [$account->id], $dates),
                'insightsStatus' => $diagnostic?->insights_status,
                'syncedAt' => $diagnostic?->insights_synced_at?->toIso8601String(),
            ];
        }

        $posts = $this->postSection($selectedIds, $today, $from, $timezone, $dates, $now);

        $platformReady = false;

        $candidates = $selected !== [] ? $selected : $available;

        foreach ($candidates as $account) {
            $platform = $this->platforms->find($account->platform);

            if ($platform !== null && $platform->availability()->available && $platform->capabilities()->insights) {
                $platformReady = true;
                break;
            }
        }

        $reported = $this->daysWithData($rows, $selectedIds, $dates);
        $notes = $this->notes($selected, $diagnostics, $kpis, $reported, $days);

        return [
            'state' => $this->state($available, $everyAccountExists, $platformReady, $reported > 0 || $this->daysWithData($rows, $selectedIds, $previousDates) > 0, $diagnostics),
            'period' => [
                'days' => $days,
                'from' => $from->format('Y-m-d'),
                'to' => $today->format('Y-m-d'),
                'previousFrom' => $previousFrom->format('Y-m-d'),
                'previousTo' => $previousTo->format('Y-m-d'),
                'timezone' => $timezone,
                'dates' => $dates,
                'options' => self::PERIODS,
            ],
            'pages' => array_map(static fn (SocialAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'platform' => $account->platform,
                'selected' => in_array($account->id, $selectedIds, true),
            ], $available),
            'selected' => $selectedIds,
            'maxPages' => self::MAX_PAGES,
            'kpis' => $kpis,
            'trend' => $trend,
            'comparison' => $comparison,
            'posts' => $posts,
            'coverage' => [
                'lastUpdatedAt' => $this->lastUpdated($diagnostics),
                'daysReported' => $reported,
                'expectedDays' => $days,
                'notes' => $notes,
            ],
        ];
    }

    /**
     * Daily rows for the CSV export: one row per account per day, figures null when not reported.
     *
     * @param  list<string>  $accountIds
     * @return list<array<string, int|string|null>>
     */
    public function dailyRows(array $accountIds, string $from, string $to): array
    {
        $accounts = SocialAccount::query()->whereIn('id', $accountIds)->orderBy('name')->get()->keyBy('id');
        $out = [];

        $insights = PageInsight::query()
            ->whereIn('account_id', $accountIds)
            ->whereDate('metric_date', '>=', $from)->whereDate('metric_date', '<=', $to)
            ->orderBy('metric_date')->orderBy('account_id')
            ->limit(5000)->get();

        foreach ($insights as $row) {
            $out[] = [
                'date' => $row->metric_date->format('Y-m-d'),
                'account' => $accounts->get($row->account_id)->name ?? '',
                'platform' => $accounts->get($row->account_id)->platform ?? '',
                ...array_combine(PageInsight::FIGURES, array_map(static fn (string $figure): ?int => $row->{$figure}, PageInsight::FIGURES)),
            ];
        }

        return $out;
    }

    /**
     * Per-campaign totals of the posts published in `[$fromAt, $toAt]` on the accounts in `$accountIds`.
     *
     * @param  list<string>|null  $accountIds  null means every account
     * @return list<array<string, mixed>>
     */
    public function campaigns(CarbonImmutable $fromAt, CarbonImmutable $toAt, ?array $accountIds, string $timezone): array
    {
        $targets = $this->targets($accountIds, $fromAt, $toAt);

        return $this->campaignRows($targets, $this->latestMetrics($targets), $timezone);
    }

    /**
     * One row per published target in `[$fromAt, $toAt]` with the latest figures its platform returned.
     *
     * @param  list<string>|null  $accountIds
     * @return list<array<string, int|string|null>>
     */
    public function postRows(CarbonImmutable $fromAt, CarbonImmutable $toAt, ?array $accountIds, string $timezone): array
    {
        $targets = $this->targets($accountIds, $fromAt, $toAt);
        $metrics = $this->latestMetrics($targets);
        $kinds = $this->mediaKinds($targets);
        $rows = [];

        foreach ($targets as $target) {
            $metric = $metrics[$target->id] ?? null;
            $rows[] = [
                'published_at' => $target->published_at?->setTimezone($timezone)->format('Y-m-d H:i'),
                'account' => $target->account->name,
                'platform' => $target->account->platform,
                'title' => $target->post->title ?? mb_strimwidth((string) $target->post->body, 0, 80, '…'),
                'type' => $kinds[$target->post_id] ?? 'text',
                'engagement' => $metric === null ? null : $this->engagement($metric),
                ...array_combine(self::POST_FIGURES, array_map(static fn (string $f): ?int => $metric?->{$f}, self::POST_FIGURES)),
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $dates[] = $day->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * @param  list<string>  $accountIds
     * @return array<string, array<string, array<string, int|null>>> account => date => figure => value
     */
    private function rows(array $accountIds, string $from, string $to): array
    {
        if ($accountIds === []) {
            return [];
        }

        $out = [];

        PageInsight::query()
            ->whereIn('account_id', $accountIds)
            ->whereDate('metric_date', '>=', $from)->whereDate('metric_date', '<=', $to)
            ->get()
            ->each(function (PageInsight $row) use (&$out): void {
                $values = [];

                foreach (PageInsight::FIGURES as $figure) {
                    $values[$figure] = $row->{$figure};
                }

                $out[$row->account_id][$row->metric_date->format('Y-m-d')] = $values;
            });

        return $out;
    }

    /**
     * @param  array<string, array<string, array<string, int|null>>>  $rows
     * @param  list<string>  $accountIds
     * @param  list<string>  $dates
     * @return array<string, array{value: int|null, days: int, asOf: string|null}>
     */
    private function summarise(array $rows, array $accountIds, array $dates): array
    {
        $summary = [];

        foreach (PageInsight::FIGURES as $figure) {
            $value = null;
            $reportedDates = [];
            $asOf = null;

            foreach ($accountIds as $id) {
                if (in_array($figure, self::LEVELS, true)) {
                    foreach (array_reverse($dates) as $date) {
                        $level = $rows[$id][$date][$figure] ?? null;

                        if ($level !== null) {
                            $value = ($value ?? 0) + $level;
                            $reportedDates[$date] = true;
                            $asOf = $asOf === null || $date > $asOf ? $date : $asOf;
                            break;
                        }
                    }

                    continue;
                }

                foreach ($dates as $date) {
                    $amount = $rows[$id][$date][$figure] ?? null;

                    if ($amount !== null) {
                        $value = ($value ?? 0) + $amount;
                        $reportedDates[$date] = true;
                    }
                }
            }

            $summary[$figure] = ['value' => $value, 'days' => count($reportedDates), 'asOf' => $asOf];
        }

        return $summary;
    }

    /**
     * @param  array<string, array<string, array<string, int|null>>>  $rows
     * @param  list<string>  $accountIds
     * @param  list<string>  $dates
     */
    private function daysWithData(array $rows, array $accountIds, array $dates): int
    {
        $seen = [];

        foreach ($accountIds as $id) {
            foreach ($dates as $date) {
                foreach ($rows[$id][$date] ?? [] as $value) {
                    if ($value !== null) {
                        $seen[$date] = true;
                        break;
                    }
                }
            }
        }

        return count($seen);
    }

    /**
     * @param  list<SocialAccount>  $available
     * @param  Collection<string, AccountDiagnostic>  $diagnostics
     */
    private function state(array $available, bool $accountsExist, bool $platformReady, bool $hasData, $diagnostics): string
    {
        if ($available === []) {
            return $accountsExist ? 'restricted' : 'no_accounts';
        }

        if (! $platformReady) {
            return 'not_configured';
        }

        if ($hasData) {
            return 'ready';
        }

        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->insights_status === 'permission_missing') {
                return 'permission_missing';
            }
        }

        return 'waiting';
    }

    /**
     * @param  list<SocialAccount>  $selected
     * @param  Collection<string, AccountDiagnostic>  $diagnostics
     * @param  list<array<string, mixed>>  $kpis
     * @return list<string>
     */
    private function notes(array $selected, $diagnostics, array $kpis, int $reported, int $days): array
    {
        $notes = [];

        if ($reported > 0) {
            $notes[] = 'platform_days';
            $notes[] = 'daily_unique_sum';
        }

        foreach ($kpis as $kpi) {
            if ($kpi['value'] !== null && ! $kpi['level'] && $kpi['days'] < $days) {
                $notes[] = 'partial_days';
                break;
            }
        }

        if ($reported > 0) {
            foreach ($kpis as $kpi) {
                if ($kpi['value'] === null) {
                    $notes[] = 'some_not_reported';
                    break;
                }
            }
        }

        foreach ($selected as $account) {
            $diagnostic = $diagnostics->get($account->id);

            if ($diagnostic === null || $diagnostic->insights_synced_at === null) {
                $notes[] = 'never_read';
            } elseif (in_array($diagnostic->insights_status, ['permission_missing', 'rate_limited', 'throttled', 'token_invalid', 'unavailable'], true)) {
                $notes[] = (string) $diagnostic->insights_status;
            }
        }

        return array_values(array_unique($notes));
    }

    /** @param  Collection<string, AccountDiagnostic>  $diagnostics */
    private function lastUpdated($diagnostics): ?string
    {
        $latest = null;

        foreach ($diagnostics as $diagnostic) {
            $at = $diagnostic->insights_synced_at;

            if ($at !== null && ($latest === null || $at->gt($latest))) {
                $latest = $at;
            }
        }

        return $latest?->toIso8601String();
    }

    /**
     * @param  list<string>  $accountIds
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function postSection(array $accountIds, CarbonImmutable $today, CarbonImmutable $from, string $timezone, array $dates, CarbonImmutable $now): array
    {
        $window = $today->subDays(self::POST_WINDOW_DAYS - 1)->startOfDay();
        $targets = $accountIds === [] ? collect() : $this->targets($accountIds, $window->utc(), $now->utc());
        $metrics = $this->latestMetrics($targets);
        $kinds = $this->mediaKinds($targets);
        $periodStart = $from->startOfDay()->utc();

        $period = $targets->filter(static fn (PostTarget $target): bool => $target->published_at !== null && $target->published_at->gte($periodStart))->values();

        $ranked = [];

        foreach ($period as $target) {
            $metric = $metrics[$target->id] ?? null;
            $engagement = $metric === null ? null : $this->engagement($metric);

            if ($engagement === null) {
                continue;
            }

            $ranked[] = $this->postRow($target, $metric, $engagement);
        }

        usort($ranked, static fn (array $a, array $b): int => [$b['engagement'], $b['publishedAt']] <=> [$a['engagement'], $a['publishedAt']]);
        $top = array_slice($ranked, 0, 5);
        $rest = array_slice($ranked, 5);
        $bottom = array_slice(array_reverse($rest), 0, 5);

        return [
            'published' => $period->count(),
            'reported' => count($ranked),
            'top' => $top,
            'bottom' => $bottom,
            'contentTypes' => $this->contentTypes($period, $metrics, $kinds),
            'campaigns' => $this->campaignRows($period, $metrics, $timezone, $dates),
            'bestTimes' => $this->bestTimes($targets, $metrics, $timezone),
        ];
    }

    /**
     * @param  list<string>|null  $accountIds
     * @return Collection<int, PostTarget>
     */
    private function targets(?array $accountIds, CarbonImmutable $fromAt, CarbonImmutable $toAt)
    {
        $query = PostTarget::query()
            ->with(['account:id,platform,name', 'post:id,title,body,campaign_id,link_url,published_at'])
            ->where('status', TargetStatus::Published->value)
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$fromAt, $toAt]);

        if ($accountIds !== null) {
            if ($accountIds === []) {
                return collect();
            }

            $query->whereIn('account_id', $accountIds);
        }

        return $query->orderByDesc('published_at')->limit(5000)->get();
    }

    /**
     * @param  Collection<int, PostTarget>  $targets
     * @return array<string, PostMetric>
     */
    private function latestMetrics($targets): array
    {
        $latest = [];
        $ids = $targets->pluck('id')->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (PostMetric::query()->whereIn('target_id', $chunk)->orderByDesc('captured_at')->get() as $metric) {
                $latest[$metric->target_id] ??= $metric;
            }
        }

        return $latest;
    }

    /**
     * @param  Collection<int, PostTarget>  $targets
     * @return array<string, string> post id => text|image|video|link
     */
    private function mediaKinds($targets): array
    {
        $postIds = $targets->pluck('post_id')->unique()->values()->all();
        $kinds = [];

        foreach (array_chunk($postIds, 500) as $chunk) {
            foreach (PostMedia::query()->whereIn('post_id', $chunk)->with('media:id,kind')->get() as $link) {
                $kind = $link->media->kind ?? null;

                if ($kind === MediaKind::Video) {
                    $kinds[$link->post_id] = 'video';
                } elseif ($kind === MediaKind::Image && ($kinds[$link->post_id] ?? null) !== 'video') {
                    $kinds[$link->post_id] = 'image';
                }
            }
        }

        $out = [];

        foreach ($targets as $target) {
            $out[$target->post_id] = $kinds[$target->post_id] ?? (($target->post->link_url ?? null) !== null && $target->post->link_url !== '' ? 'link' : 'text');
        }

        return $out;
    }

    /** Likes + comments + shares of the figures that were reported; null when none was. */
    private function engagement(PostMetric $metric): ?int
    {
        if ($metric->likes === null && $metric->comments === null && $metric->shares === null) {
            return null;
        }

        return ($metric->likes ?? 0) + ($metric->comments ?? 0) + ($metric->shares ?? 0);
    }

    /** @return array<string, mixed> */
    private function postRow(PostTarget $target, PostMetric $metric, int $engagement): array
    {
        return [
            'targetId' => $target->id,
            'postId' => $target->post_id,
            'title' => $target->post->title ?? mb_strimwidth((string) $target->post->body, 0, 80, '…'),
            'accountName' => $target->account->name,
            'platform' => $target->account->platform,
            'url' => $target->external_url,
            'publishedAt' => $target->published_at?->toIso8601String(),
            'engagement' => $engagement,
            'figures' => array_combine(self::POST_FIGURES, array_map(static fn (string $f): ?int => $metric->{$f}, self::POST_FIGURES)),
        ];
    }

    /**
     * @param  Collection<int, PostTarget>  $period
     * @param  array<string, PostMetric>  $metrics
     * @param  array<string, string>  $kinds
     * @return list<array<string, mixed>>
     */
    private function contentTypes($period, array $metrics, array $kinds): array
    {
        $groups = [];

        foreach ($period as $target) {
            $type = $kinds[$target->post_id] ?? 'text';
            $groups[$type] ??= ['type' => $type, 'posts' => 0, 'reported' => 0, 'engagement' => null, 'totals' => array_fill_keys(self::POST_FIGURES, null)];
            $groups[$type]['posts']++;
            $metric = $metrics[$target->id] ?? null;
            $engagement = $metric === null ? null : $this->engagement($metric);

            if ($metric === null || $engagement === null) {
                continue;
            }

            $groups[$type]['reported']++;
            $groups[$type]['engagement'] = ($groups[$type]['engagement'] ?? 0) + $engagement;

            foreach (self::POST_FIGURES as $figure) {
                if ($metric->{$figure} !== null) {
                    $groups[$type]['totals'][$figure] = ($groups[$type]['totals'][$figure] ?? 0) + $metric->{$figure};
                }
            }
        }

        $rows = [];

        foreach (['text', 'image', 'video', 'link'] as $type) {
            if (isset($groups[$type])) {
                $group = $groups[$type];
                $group['average'] = $group['reported'] > 0 && $group['engagement'] !== null ? round($group['engagement'] / $group['reported'], 1) : null;
                $rows[] = $group;
            }
        }

        return $rows;
    }

    /**
     * @param  Collection<int, PostTarget>  $targets
     * @param  array<string, PostMetric>  $metrics
     * @param  list<string>|null  $dates
     * @return list<array<string, mixed>>
     */
    private function campaignRows($targets, array $metrics, string $timezone, ?array $dates = null): array
    {
        $names = Campaign::query()->whereIn('id', $targets->pluck('post.campaign_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');
        $groups = [];

        foreach ($targets as $target) {
            $id = $target->post->campaign_id ?? null;
            $key = $id ?? '';
            $groups[$key] ??= [
                'id' => $id,
                'name' => $id === null ? null : ($names[$id] ?? null),
                'posts' => 0,
                'reported' => 0,
                'engagement' => null,
                'totals' => array_fill_keys(self::POST_FIGURES, null),
                'timeline' => [],
            ];
            $groups[$key]['posts']++;
            $date = $target->published_at?->setTimezone($timezone)->format('Y-m-d');
            $metric = $metrics[$target->id] ?? null;
            $engagement = $metric === null ? null : $this->engagement($metric);

            if ($date !== null) {
                $groups[$key]['timeline'][$date] ??= ['date' => $date, 'posts' => 0, 'engagement' => null];
                $groups[$key]['timeline'][$date]['posts']++;
            }

            if ($metric === null || $engagement === null) {
                continue;
            }

            $groups[$key]['reported']++;
            $groups[$key]['engagement'] = ($groups[$key]['engagement'] ?? 0) + $engagement;

            if ($date !== null) {
                $groups[$key]['timeline'][$date]['engagement'] = ($groups[$key]['timeline'][$date]['engagement'] ?? 0) + $engagement;
            }

            foreach (self::POST_FIGURES as $figure) {
                if ($metric->{$figure} !== null) {
                    $groups[$key]['totals'][$figure] = ($groups[$key]['totals'][$figure] ?? 0) + $metric->{$figure};
                }
            }
        }

        $rows = array_values($groups);

        foreach ($rows as &$row) {
            ksort($row['timeline']);
            $row['timeline'] = array_values($row['timeline']);
        }

        unset($row);

        usort($rows, static fn (array $a, array $b): int => [$b['posts'], (string) $a['name']] <=> [$a['posts'], (string) $b['name']]);

        return array_slice($rows, 0, 25);
    }

    /**
     * @param  Collection<int, PostTarget>  $targets
     * @param  array<string, PostMetric>  $metrics
     * @return array<string, mixed>
     */
    private function bestTimes($targets, array $metrics, string $timezone): array
    {
        $hours = array_fill(0, 24, ['posts' => 0, 'engagement' => 0]);
        $weekdays = array_fill(0, 7, ['posts' => 0, 'engagement' => 0]);
        $slots = [];
        $sample = 0;

        foreach ($targets as $target) {
            $metric = $metrics[$target->id] ?? null;
            $engagement = $metric === null ? null : $this->engagement($metric);

            if ($engagement === null || $target->published_at === null) {
                continue;
            }

            $local = CarbonImmutable::instance($target->published_at)->setTimezone($timezone);
            $hour = (int) $local->format('G');
            $weekday = (int) $local->format('w');
            $sample++;
            $hours[$hour]['posts']++;
            $hours[$hour]['engagement'] += $engagement;
            $weekdays[$weekday]['posts']++;
            $weekdays[$weekday]['engagement'] += $engagement;
            $slots[$weekday.'-'.$hour] ??= ['weekday' => $weekday, 'hour' => $hour, 'posts' => 0, 'engagement' => 0];
            $slots[$weekday.'-'.$hour]['posts']++;
            $slots[$weekday.'-'.$hour]['engagement'] += $engagement;
        }

        $average = static fn (array $bucket): ?float => $bucket['posts'] > 0 ? round($bucket['engagement'] / $bucket['posts'], 1) : null;
        $minimum = max(1, (int) config('social.insights.min_posts_for_best_time', 10));

        $best = [];

        foreach ($slots as $slot) {
            if ($slot['posts'] >= 2) {
                $best[] = [...$slot, 'average' => $average($slot)];
            }
        }

        usort($best, static fn (array $a, array $b): int => [$b['average'], $b['posts']] <=> [$a['average'], $a['posts']]);

        return [
            'windowDays' => self::POST_WINDOW_DAYS,
            'timezone' => $timezone,
            'posts' => $sample,
            'minimum' => $minimum,
            'sufficient' => $sample >= $minimum,
            'byHour' => array_map(static fn (int $hour, array $bucket): array => [
                'hour' => $hour, 'posts' => $bucket['posts'], 'engagement' => $bucket['posts'] > 0 ? $bucket['engagement'] : null,
                'average' => $average($bucket),
            ], array_keys($hours), $hours),
            'byWeekday' => array_map(static fn (int $day, array $bucket): array => [
                'weekday' => $day, 'posts' => $bucket['posts'], 'engagement' => $bucket['posts'] > 0 ? $bucket['engagement'] : null,
                'average' => $average($bucket),
            ], array_keys($weekdays), $weekdays),
            'slots' => array_slice($best, 0, 3),
        ];
    }
}
