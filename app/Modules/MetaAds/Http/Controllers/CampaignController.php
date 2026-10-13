<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Http\Controllers;

use App\Core\Support\Like;
use App\Core\Support\Toast;
use App\Modules\MetaAds\Actions\ChangeManager;
use App\Modules\MetaAds\Actions\MetaAdsSettings;
use App\Modules\MetaAds\Actions\PerformanceReport;
use App\Modules\MetaAds\Enums\AdAccountStatus;
use App\Modules\MetaAds\Enums\ChangeAction;
use App\Modules\MetaAds\Enums\ObjectLevel;
use App\Modules\MetaAds\Http\Requests\BudgetChangeRequest;
use App\Modules\MetaAds\Http\Requests\CampaignListRequest;
use App\Modules\MetaAds\Http\Requests\StatusChangeRequest;
use App\Modules\MetaAds\Models\AdAccount;
use App\Modules\MetaAds\Models\AdObject;
use App\Modules\MetaAds\Models\Insight;
use App\Modules\MetaAds\Support\Figures;
use App\Modules\MetaAds\Support\MinorUnits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Campaigns, ad sets and ads as last synced, with the three changes this app can make to Meta. A change needs
 * `meta_ads.campaigns.manage` on the route and is re-checked, confirmed and receipted by {@see ChangeManager}.
 */
final class CampaignController
{
    public function __construct(
        private readonly ChangeManager $changes,
        private readonly PerformanceReport $performance,
        private readonly MetaAdsSettings $settings,
        private readonly MetaAdsSupport $support,
    ) {}

    public function index(CampaignListRequest $request): Response
    {
        $actor = $this->support->actor($request);
        $level = ObjectLevel::tryFrom((string) $request->validated('level', 'campaign')) ?? ObjectLevel::Campaign;
        $status = (string) $request->validated('status', 'all');
        $search = trim((string) $request->validated('q', ''));
        $range = $this->performance->range($request->validated('from'), $request->validated('to'));

        $tracked = AdAccount::query()->where('status', AdAccountStatus::Tracked->value)->orderBy('name')->get();
        $accountId = $request->validated('account');

        if (is_string($accountId) && $accountId !== '') {
            $tracked->firstWhere('id', $accountId) ?? abort(404);
        } else {
            $accountId = null;
        }

        $query = AdObject::query()->with('account')
            ->where('level', $level->value)
            ->whereNull('missing_at')
            ->whereIn('account_id', $accountId !== null ? [$accountId] : $tracked->pluck('id')->all());

        match ($status) {
            'active' => $query->where('status', 'ACTIVE'),
            'paused' => $query->where('status', 'PAUSED'),
            'other' => $query->where(static fn ($inner) => $inner->whereNotIn('status', ['ACTIVE', 'PAUSED'])->orWhereNull('status')),
            default => null,
        };

        if ($search !== '') {
            $query->where('name', 'like', Like::contains($search));
        }

        $page = $query->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();
        $items = collect($page->items());

        $insights = $level === ObjectLevel::Campaign ? $this->insightsFor($items, $range) : [];
        $parents = $this->parents($items, $level);
        $canManage = $actor->can('meta_ads.campaigns.manage');
        $managementOn = $this->settings->managementEnabled();
        $accountReasons = [];

        $page->through(function (AdObject $object) use ($insights, $parents, $canManage, $managementOn, &$accountReasons): array {
            $account = $object->account;
            if (! array_key_exists($object->account_id, $accountReasons)) {
                $accountReasons[$object->account_id] = $this->changes->accountBlockReason($account);
            }

            $reason = $accountReasons[$object->account_id] ?? $this->changes->objectBlockReason($object);
            $open = $canManage && $managementOn && $reason === null;
            $field = $object->daily_budget !== null ? 'daily_budget' : ($object->lifetime_budget !== null ? 'lifetime_budget' : null);
            $ceiling = $field === null ? null : ($field === 'daily_budget' ? $account->daily_budget_ceiling : $account->lifetime_budget_ceiling);
            $key = $object->account_id.'|'.$object->external_id;

            return [
                'id' => $object->id,
                'externalId' => $object->external_id,
                'level' => $object->level->value,
                'name' => $object->name,
                'status' => $object->status,
                'effectiveStatus' => $object->effective_status,
                'objective' => $object->objective,
                'dailyBudget' => $object->daily_budget,
                'lifetimeBudget' => $object->lifetime_budget,
                'budgetRemaining' => $object->budget_remaining,
                'currency' => $object->currency,
                'startAt' => $object->start_at?->toIso8601String(),
                'endAt' => $object->end_at?->toIso8601String(),
                'accountId' => $object->account_id,
                'accountName' => $account->name,
                'parentName' => $parents[$object->campaign_external_id.'|'.$object->account_id] ?? null,
                'insights' => $insights[$key] ?? null,
                'canPause' => $open && $object->status === 'ACTIVE',
                'canResume' => $open && $object->status === 'PAUSED',
                'canEditBudget' => $open && $object->level !== ObjectLevel::Ad && $field !== null && $ceiling !== null,
                'budgetField' => $field,
                'ceiling' => $ceiling,
                'blockedReason' => $canManage && $managementOn ? $reason : null,
            ];
        });

        return Inertia::render('Modules/MetaAds/Pages/Campaigns/Index', [
            'objects' => $page,
            'level' => $level->value,
            'filters' => ['status' => $status, 'q' => $search, 'account' => $accountId],
            'range' => $range,
            'accounts' => $tracked->map(static fn (AdAccount $a): array => ['id' => $a->id, 'name' => $a->name, 'currency' => $a->currency])->values()->all(),
            'management' => [
                'canManage' => $canManage,
                'enabled' => $managementOn,
                'maxIncreasePercent' => (int) config('meta_ads.changes.max_increase_percent', 100),
            ],
            'hasInsights' => $level === ObjectLevel::Campaign,
        ]);
    }

    public function status(StatusChangeRequest $request, string $object): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $target = AdObject::query()->findOrFail($object);
        $action = ChangeAction::from((string) $request->validated('action'));

        $this->changes->apply(
            $target,
            $action,
            (string) $request->validated('expected'),
            null,
            (string) $request->validated('request_id'),
            $this->support->membership($actor),
        );

        Toast::success($action === ChangeAction::Pause ? __('Paused on Meta.') : __('Resumed on Meta.'));

        return back();
    }

    public function budget(BudgetChangeRequest $request, string $object): RedirectResponse
    {
        $actor = $this->support->actor($request);
        $target = AdObject::query()->with('account')->findOrFail($object);
        $minor = MinorUnits::fromInput((string) $request->validated('amount'), $target->account->currency);

        if ($minor === null) {
            throw ValidationException::withMessages(['budget' => __('Enter the budget as a plain amount, for example 1500 or 1500.50.')]);
        }

        $this->changes->apply(
            $target,
            ChangeAction::Budget,
            (string) $request->validated('expected'),
            $minor,
            (string) $request->validated('request_id'),
            $this->support->membership($actor),
        );

        Toast::success(__('Budget updated on Meta.'));

        return back();
    }

    /**
     * @param  Collection<int, AdObject>  $objects
     * @param  array{from: string, to: string, days: int}  $range
     * @return array<string, array<string, int|null>>
     */
    private function insightsFor($objects, array $range): array
    {
        if ($objects->isEmpty()) {
            return [];
        }

        $rows = Insight::query()->where('level', Insight::LEVEL_CAMPAIGN)
            ->whereBetween('metric_date', [$range['from'], $range['to']])
            ->whereIn('account_id', $objects->pluck('account_id')->unique()->all())
            ->whereIn('object_external_id', $objects->pluck('external_id')->all())
            ->selectRaw('account_id, object_external_id, SUM(spend) as spend, SUM(impressions) as impressions, SUM(clicks) as clicks, SUM(leads) as leads')
            ->groupBy('account_id', 'object_external_id')->get();

        $result = [];

        foreach ($rows as $row) {
            $spend = is_numeric($row->getAttribute('spend')) ? (int) $row->getAttribute('spend') : null;
            $impressions = is_numeric($row->getAttribute('impressions')) ? (int) $row->getAttribute('impressions') : null;
            $clicks = is_numeric($row->getAttribute('clicks')) ? (int) $row->getAttribute('clicks') : null;
            $leads = is_numeric($row->getAttribute('leads')) ? (int) $row->getAttribute('leads') : null;
            $result[$row->account_id.'|'.$row->object_external_id] = [
                'spend' => $spend, 'impressions' => $impressions, 'clicks' => $clicks, 'leads' => $leads,
                'ctr' => Figures::ctr($clicks, $impressions), 'costPerLead' => Figures::costPerLead($spend, $leads),
            ];
        }

        return $result;
    }

    /**
     * Campaign names for ad sets and ads, keyed `campaignExternalId|accountId`.
     *
     * @param  Collection<int, AdObject>  $objects
     * @return array<string, string>
     */
    private function parents($objects, ObjectLevel $level): array
    {
        if ($level === ObjectLevel::Campaign || $objects->isEmpty()) {
            return [];
        }

        return AdObject::query()->where('level', ObjectLevel::Campaign->value)
            ->whereIn('account_id', $objects->pluck('account_id')->unique()->all())
            ->whereIn('external_id', $objects->pluck('campaign_external_id')->filter()->unique()->all())
            ->get(['external_id', 'account_id', 'name'])
            ->mapWithKeys(static fn (AdObject $o): array => [$o->external_id.'|'.$o->account_id => $o->name])->all();
    }
}
