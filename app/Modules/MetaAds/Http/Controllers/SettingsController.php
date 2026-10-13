<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Support\Toast;
use App\Modules\MetaAds\Actions\MetaAdsSettings;
use App\Modules\MetaAds\Api\MarketingApi;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Http\Requests\SettingsRequest;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Support\MinorUnits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workspace settings: whether changes to Meta may be made from here at all, the insight history window, and
 * the budget ceilings per ad account. A ceiling is the most a budget may ever be set to from this app.
 */
final class SettingsController
{
    public function __construct(
        private readonly MetaAdsSettings $settings,
        private readonly MetaAdsSupport $support,
        private readonly MetaAdsPresenter $presenter,
        private readonly MarketingApi $api,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $this->support->actor($request);
        $settings = $this->settings->current();

        return Inertia::render('Modules/MetaAds/Pages/Settings/Index', [
            'settings' => [
                'managementEnabled' => $settings->management_enabled,
                'lookbackDays' => $this->settings->lookbackDays(),
            ],
            'managementAvailable' => in_array('ads_management', $this->api->scopes(), true),
            'bounds' => [
                'minLookback' => (int) config('meta_ads.sync.min_lookback_days', 7),
                'maxLookback' => (int) config('meta_ads.sync.max_lookback_days', 90),
                'maxIncreasePercent' => (int) config('meta_ads.changes.max_increase_percent', 100),
                'maxChangesPerDay' => (int) config('meta_ads.changes.max_changes_per_day', 30),
            ],
            'ceilings' => AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->orderBy('name')->get()
                ->map(fn (AdAccount $account): array => $this->presenter->ceilingForm($account))->all(),
        ]);
    }

    public function update(SettingsRequest $request): RedirectResponse
    {
        $this->support->actor($request);

        /** @var list<array{account_id: string, daily: string|null, lifetime: string|null}> $ceilings */
        $ceilings = $request->validated('ceilings', []);
        $parsed = [];

        foreach ($ceilings as $index => $row) {
            $account = AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->findOrFail($row['account_id']);
            $daily = $this->amount($row['daily'] ?? null, $account->currency, "ceilings.{$index}.daily");
            $lifetime = $this->amount($row['lifetime'] ?? null, $account->currency, "ceilings.{$index}.lifetime");
            $parsed[] = [$account, $daily, $lifetime];
        }

        DB::transaction(function () use ($request, $parsed): void {
            $this->settings->update([
                'management_enabled' => (bool) $request->validated('management_enabled'),
                'lookback_days' => (int) $request->validated('lookback_days'),
            ]);

            foreach ($parsed as [$account, $daily, $lifetime]) {
                if ($account->daily_budget_ceiling === $daily && $account->lifetime_budget_ceiling === $lifetime) {
                    continue;
                }

                $before = ['daily' => $account->daily_budget_ceiling, 'lifetime' => $account->lifetime_budget_ceiling];
                $account->forceFill(['daily_budget_ceiling' => $daily, 'lifetime_budget_ceiling' => $lifetime])->save();
                $this->audit->record('meta_ads.account.ceilings_changed', $account, [
                    'account' => $account->external_id,
                    'currency' => $account->currency,
                    'before' => $before,
                    'after' => ['daily' => $daily, 'lifetime' => $lifetime],
                ]);
            }
        });

        Toast::success(__('Settings saved.'));

        return back();
    }

    /** A positive amount in the account currency typed as a decimal, in minor units; empty means "not set". */
    private function amount(?string $value, string $currency, string $field): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $minor = MinorUnits::fromInput($value, $currency);

        if ($minor === null || $minor < 1) {
            throw ValidationException::withMessages([$field => __('Enter a plain amount greater than zero, or leave it empty.')]);
        }

        return $minor;
    }
}
