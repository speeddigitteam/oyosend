<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Access\RecordAccess;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use App\Modules\CRM\Enums\ContractStatus;
use App\Modules\CRM\Enums\QuoteStatus;
use App\Modules\CRM\Enums\SalesOrderStatus;
use App\Modules\CRM\Models\Contract;
use App\Modules\CRM\Models\Quote;
use App\Modules\CRM\Models\SalesOrder;
use App\Modules\CRM\Models\SalesTarget;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class SalesAnalytics
{
    public function __construct(
        private readonly RecordAccess $records,
        private readonly TenantContext $context,
    ) {}

    /**
     * All amounts are integer minor units; rates are integer basis points.
     *
     * @return array<string, int|string>
     */
    public function overview(User $actor, CarbonInterface $from, CarbonInterface $to, string $currency): array
    {
        $currency = strtoupper($currency);
        [$fromUtc, $toUtc] = $this->periodBounds($from, $to);
        $quotes = $this->records->constrain(Quote::query(), $actor, 'crm.reports.view')
            ->where('currency', $currency)
            ->whereBetween('created_at', [$fromUtc, $toUtc]);
        $orders = $this->records->constrain(SalesOrder::query(), $actor, 'crm.reports.view')
            ->where('currency', $currency)
            ->whereBetween('ordered_at', [$fromUtc, $toUtc]);

        $accepted = (clone $quotes)->where('status', QuoteStatus::Accepted)->count();
        $decided = (clone $quotes)->whereIn('status', [
            QuoteStatus::Accepted,
            QuoteStatus::Rejected,
            QuoteStatus::Expired,
        ])->count();
        $actual = SalesMath::sum([(clone $orders)
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::Fulfilled])
            ->sum('total_amount')]);
        $weighted = 0;

        foreach ((clone $quotes)->whereIn('status', [QuoteStatus::Draft, QuoteStatus::Sent])
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $this->localToday()))
            ->get(['id', 'total_amount', 'probability_percent']) as $quote) {
            $weighted = SalesMath::add(
                $weighted,
                SalesMath::multiplyDivide($quote->total_amount, $quote->probability_percent, 100, RoundingMode::HalfUp),
            );
        }

        $target = SalesMath::sum([$this->records->constrain(SalesTarget::query(), $actor, 'crm.reports.view')
            ->where('currency', $currency)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->sum('target_amount')]);
        $activeContracts = SalesMath::sum([$this->records->constrain(Contract::query(), $actor, 'crm.reports.view')
            ->where('currency', $currency)
            ->where('status', ContractStatus::Active)
            ->sum('value_amount')]);

        return [
            'currency' => $currency,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'quoted_amount' => SalesMath::sum([(clone $quotes)->sum('total_amount')]),
            'open_weighted_amount' => $weighted,
            'actual_sales_amount' => $actual,
            'forecast_amount' => SalesMath::add($actual, $weighted),
            'target_amount' => $target,
            'target_gap_amount' => SalesMath::add($target, -$actual),
            'target_attainment_bps' => SalesMath::ratioBps($actual, $target),
            'active_contract_value_amount' => $activeContracts,
            'quote_count' => (clone $quotes)->count(),
            'accepted_quote_count' => $accepted,
            'order_count' => (clone $orders)->count(),
            'conversion_bps' => SalesMath::ratioBps($accepted, $decided),
        ];
    }

    public function targetActual(SalesTarget $target): int
    {
        [$fromUtc, $toUtc] = $this->periodBounds($target->period_start, $target->period_end);
        $query = SalesOrder::query()
            ->where('currency', $target->currency)
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::Fulfilled])
            ->whereBetween('ordered_at', [$fromUtc, $toUtc]);

        if ($target->owner_id !== null) {
            $query->where('owner_id', $target->owner_id);
        } elseif ($target->department_id !== null) {
            $query->where('department_id', $target->department_id);
        } elseif ($target->branch_id !== null) {
            $query->where('branch_id', $target->branch_id);
        }

        return SalesMath::sum([$query->sum('total_amount')]);
    }

    /** @return list<array{status: string, count: int, amount: int}> */
    public function quoteStatusBreakdown(User $actor, CarbonInterface $from, CarbonInterface $to, string $currency): array
    {
        [$fromUtc, $toUtc] = $this->periodBounds($from, $to);
        $rows = [];

        foreach (QuoteStatus::cases() as $status) {
            $query = $this->records->constrain(Quote::query(), $actor, 'crm.reports.view')
                ->where('currency', strtoupper($currency))
                ->where('status', $status)
                ->whereBetween('created_at', [$fromUtc, $toUtc]);
            $rows[] = [
                'status' => $status->value,
                'count' => (clone $query)->count(),
                'amount' => SalesMath::sum([$query->sum('total_amount')]),
            ];
        }

        return $rows;
    }

    /** @return list<array{period: string, order_count: int, amount: int}> */
    public function orderTrend(User $actor, CarbonInterface $from, CarbonInterface $to, string $currency): array
    {
        [$fromUtc, $toUtc] = $this->periodBounds($from, $to);
        $timezone = $this->context->require()->timezone;

        return $this->records->constrain(SalesOrder::query(), $actor, 'crm.reports.view')
            ->where('currency', strtoupper($currency))
            ->whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::Fulfilled])
            ->whereBetween('ordered_at', [$fromUtc, $toUtc])
            ->orderBy('ordered_at')
            ->get(['id', 'ordered_at', 'total_amount'])
            ->groupBy(fn (SalesOrder $order): string => $order->ordered_at->copy()->timezone($timezone)->format('Y-m'))
            ->map(fn ($orders, string $period): array => [
                'period' => $period,
                'order_count' => $orders->count(),
                'amount' => SalesMath::sum($orders->pluck('total_amount')),
            ])->values()->all();
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function periodBounds(CarbonInterface $from, CarbonInterface $to): array
    {
        $timezone = $this->context->require()->timezone;

        return [
            CarbonImmutable::parse($from->toDateString(), $timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($to->toDateString(), $timezone)->endOfDay()->utc(),
        ];
    }

    private function localToday(): string
    {
        return CarbonImmutable::now($this->context->require()->timezone)->toDateString();
    }
}
