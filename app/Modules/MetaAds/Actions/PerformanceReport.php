<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Actions;

use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Support\Figures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Read model of the performance dashboard, the CSV export and the report providers.
 *
 * Every figure is summed per currency: ad accounts in different currencies are never added together, so a
 * report is always for one currency. A figure Meta did not report is null ("not reported"), never zero.
 * Dates are the ad account's own calendar days, as Meta reports them.
 */
final class PerformanceReport
{
    public const int MAX_DAYS = 366;

    public const int DEFAULT_DAYS = 30;

    public function __construct(private readonly MetaAdsSettings $settings) {}

    /**
     * @return array{from: string, to: string, days: int}
     *
     * @throws ValidationException
     */
    public function range(?string $from, ?string $to): array
    {
        $today = CarbonImmutable::now($this->settings->timezone())->startOfDay();

        try {
            $end = $to !== null && $to !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $to, $this->settings->timezone()) : $today;
            $start = $from !== null && $from !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $from, $this->settings->timezone()) : null;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['to' => __('Enter valid dates.')]);
        }
        $start ??= $end->subDays(self::DEFAULT_DAYS - 1);

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['to' => __('The end date must not be before the start date.')]);
        }

        $days = (int) $start->diffInDays($end) + 1;

        if ($days > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('Choose a range of at most :days days.', ['days' => self::MAX_DAYS])]);
        }

        return ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days];
    }

    /**
     * Currencies of the tracked ad accounts: the workspace's own currency first, then by how many ad accounts
     * use it. Amounts in different currencies are never compared, so spend does not decide the order.
     *
     * @return list<string>
     */
    public function currencies(string $from, string $to): array
    {
        $counts = AdAccount::query()->where('status', AdAccountStatus::Tracked->value)
            ->selectRaw('currency, COUNT(*) as total')->groupBy('currency')->pluck('total', 'currency')->all();

        if ($counts === []) {
            return [];
        }

        $own = $this->settings->workspaceCurrency();
        $currencies = array_map('strval', array_keys($counts));

        usort($currencies, static fn (string $a, string $b): int => [$b === $own, $counts[$b]] <=> [$a === $own, $counts[$a]] ?: strcmp($a, $b));

        return $currencies;
    }

    /**
     * Totals for the range and the equally long period before it.
     *
     * @return array{current: array<string, int|null>, previous: array<string, int|null>, deltas: array<string, int|null>}
     */
    public function summary(string $from, string $to, string $currency, ?string $accountId = null): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->subDay();
        $previousStart = $previousEnd->subDays($days - 1);

        $current = $this->totals($from, $to, $currency, $accountId);
        $previous = $this->totals($previousStart->toDateString(), $previousEnd->toDateString(), $currency, $accountId);

        $deltas = [];

        foreach (['spend', 'impressions', 'reach', 'clicks', 'leads', 'ctr', 'cpc', 'cpm', 'costPerLead'] as $key) {
            $deltas[$key] = Figures::delta($current[$key], $previous[$key]);
        }

        return ['current' => $current, 'previous' => $previous, 'deltas' => $deltas];
    }

    /**
     * One row per day of the range (a day with no data is null across, not zero).
     *
     * @return list<array{date: string, spend: int|null, impressions: int|null, clicks: int|null, leads: int|null}>
     */
    public function series(string $from, string $to, string $currency, ?string $accountId = null): array
    {
        $rows = $this->base($from, $to, $currency, $accountId)
            ->selectRaw('metric_date, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(leads) as leads')
            ->groupBy('metric_date')->get()->keyBy(static fn (Insight $row): string => $row->metric_date->toDateString());

        $series = [];

        for ($day = CarbonImmutable::parse($from); $day->lte(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $row = $rows->get($day->toDateString());
            $series[] = [
                'date' => $day->toDateString(),
                'spend' => $this->int($row?->getAttribute('spend')),
                'impressions' => $this->int($row?->getAttribute('impressions')),
                'clicks' => $this->int($row?->getAttribute('clicks')),
                'leads' => $this->int($row?->getAttribute('leads')),
            ];
        }

        return $series;
    }

    /**
     * Campaigns by spend in the range.
     *
     * @return list<array<string, mixed>>
     */
    public function campaigns(string $from, string $to, string $currency, ?string $accountId = null, int $limit = 10): array
    {
        $rows = Insight::query()->where('level', Insight::LEVEL_CAMPAIGN)
            ->where('currency', $currency)
            ->whereBetween('metric_date', [$from, $to])
            ->whereIn('account_id', $accountId !== null ? [$accountId] : $this->trackedIds())
            ->selectRaw('account_id, object_external_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(leads) as leads')
            ->groupBy('account_id', 'object_external_id')
            ->orderByRaw('SUM(spend) IS NULL, SUM(spend) DESC')
            ->limit(max(1, min(2000, $limit)))
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $objects = AdObject::query()->where('level', 'campaign')
            ->whereIn('account_id', $rows->pluck('account_id')->unique()->all())
            ->whereIn('external_id', $rows->pluck('object_external_id')->unique()->all())
            ->get()->keyBy(static fn (AdObject $object): string => $object->account_id.'|'.$object->external_id);
        $accounts = AdAccount::query()->whereIn('id', $rows->pluck('account_id')->unique()->all())->get()->keyBy('id');

        return $rows->map(function (Insight $row) use ($objects, $accounts, $currency): array {
            $spend = $this->int($row->getAttribute('spend'));
            $impressions = $this->int($row->getAttribute('impressions'));
            $clicks = $this->int($row->getAttribute('clicks'));
            $leads = $this->int($row->getAttribute('leads'));
            $object = $objects->get($row->account_id.'|'.$row->object_external_id);

            return [
                'accountId' => $row->account_id,
                'accountName' => $accounts->get($row->account_id)?->name,
                'campaignId' => $row->object_external_id,
                'objectId' => $object?->id,
                'name' => $object?->name,
                'status' => $object->effective_status ?? $object->status,
                'currency' => $currency,
                'spend' => $spend,
                'impressions' => $impressions,
                'clicks' => $clicks,
                'leads' => $leads,
                'ctr' => Figures::ctr($clicks, $impressions),
                'cpc' => Figures::cpc($spend, $clicks),
                'costPerLead' => Figures::costPerLead($spend, $leads),
            ];
        })->values()->all();
    }

    /** @return array<string, int|null> */
    public function totals(string $from, string $to, string $currency, ?string $accountId = null): array
    {
        $row = $this->base($from, $to, $currency, $accountId)
            ->selectRaw('SUM(spend) as spend, SUM(impressions) as impressions, SUM(reach) as reach, SUM(clicks) as clicks, SUM(leads) as leads')
            ->first();

        $spend = $this->int($row?->getAttribute('spend'));
        $impressions = $this->int($row?->getAttribute('impressions'));
        $clicks = $this->int($row?->getAttribute('clicks'));
        $leads = $this->int($row?->getAttribute('leads'));

        return [
            'spend' => $spend,
            'impressions' => $impressions,
            'reach' => $this->int($row?->getAttribute('reach')),
            'clicks' => $clicks,
            'leads' => $leads,
            'ctr' => Figures::ctr($clicks, $impressions),
            'cpc' => Figures::cpc($spend, $clicks),
            'cpm' => Figures::cpm($spend, $impressions),
            'costPerLead' => Figures::costPerLead($spend, $leads),
        ];
    }

    /** The oldest "last synced" of the tracked ad accounts: how fresh the figures are at worst. */
    public function freshness(): ?CarbonImmutable
    {
        $accounts = AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->get(['last_synced_at']);

        if ($accounts->isEmpty() || $accounts->contains(static fn (AdAccount $a): bool => $a->last_synced_at === null)) {
            return null;
        }

        $oldest = $accounts->min(static fn (AdAccount $a): int => $a->last_synced_at?->getTimestamp() ?? 0);

        return $oldest === null ? null : CarbonImmutable::createFromTimestamp((int) $oldest);
    }

    /**
     * @return Builder<Insight>
     */
    private function base(string $from, string $to, string $currency, ?string $accountId)
    {
        return Insight::query()->where('level', Insight::LEVEL_ACCOUNT)
            ->where('currency', $currency)
            ->whereBetween('metric_date', [$from, $to])
            ->whereIn('account_id', $accountId !== null ? [$accountId] : $this->trackedIds());
    }

    /** @return list<string> */
    private function trackedIds(): array
    {
        return AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->pluck('id')->all();
    }

    private function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
