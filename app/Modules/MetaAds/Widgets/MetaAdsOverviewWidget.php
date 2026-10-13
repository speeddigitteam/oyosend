<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Widgets;

use App\Core\Access\AccessChecker;
use App\Core\Identity\Models\User;
use App\Modules\MetaAds\Actions\MetaAdsSettings;
use App\Modules\MetaAds\Actions\PerformanceReport;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use App\Modules\MetaAds\Enums\SyncState;
use App\Modules\MetaAds\Models\AdAccount;
use Carbon\CarbonImmutable;

/**
 * Dashboard widget, shown with `meta_ads.insights.view`: the last seven days of spend and leads in the
 * ad accounts' main currency, and how many ad accounts need attention. Never adds currencies together.
 */
final class MetaAdsOverviewWidget
{
    public function __construct(
        private readonly PerformanceReport $performance,
        private readonly MetaAdsSettings $settings,
        private readonly AccessChecker $access,
    ) {}

    /** @return array<string, int|string|null> */
    public function __invoke(User $user): array
    {
        $to = CarbonImmutable::now($this->settings->timezone())->toDateString();
        $from = CarbonImmutable::parse($to)->subDays(6)->toDateString();
        $currencies = $this->performance->currencies($from, $to);
        $currency = $currencies[0] ?? null;
        $totals = $currency === null ? null : $this->performance->totals($from, $to, $currency);

        $attention = $this->access->allows($user, 'meta_ads.accounts.view')
            ? AdAccount::query()->where('status', AdAccountStatus::Tracked->value)
                ->where(static function ($query): void {
                    $query->where('sync_state', SyncState::Error->value)
                        ->orWhereHas('connection', static fn ($connection) => $connection->where('status', ConnectionStatus::NeedsReauthorization->value));
                })->count()
            : 0;

        return [
            'currency' => $currency,
            'currencies' => count($currencies),
            'spend' => $totals['spend'] ?? null,
            'leads' => $totals['leads'] ?? null,
            'trackedAccounts' => AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->count(),
            'accountsNeedingAttention' => $attention,
        ];
    }
}
