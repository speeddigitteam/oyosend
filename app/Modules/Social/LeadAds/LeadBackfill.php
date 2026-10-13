<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraph;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraphException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Fetches a Page's recent leads, form by form, for webhooks that were missed. Bounded twice: the window
 * (days) and the number of leads per run. Leads already registered are skipped by their `leadgen_id`.
 */
final class LeadBackfill
{
    public function __construct(
        private readonly LeadAdsGraph $graph,
        private readonly LeadIngest $ingest,
        private readonly LeadProcessor $processor,
        private readonly AccountManager $accounts,
    ) {}

    /** The window in days, clamped to what the operator allows. */
    public function window(?int $days): int
    {
        $max = max(1, (int) config('social.lead_ads.backfill_max_days', 14));

        return max(1, min($max, $days ?? (int) config('social.lead_ads.backfill_days', 3)));
    }

    /**
     * @return array{new: int, known: int, forms: int, stopped: string|null}
     */
    public function run(LeadAdsPage $page, int $days): array
    {
        $summary = ['new' => 0, 'known' => 0, 'forms' => 0, 'stopped' => null];
        $account = SocialAccount::query()->find($page->account_id);

        if ($account === null || $account->status !== AccountStatus::Connected || $account->access_token === null) {
            $summary['stopped'] = 'reauthorization_required';

            return $summary;
        }

        $since = CarbonImmutable::now()->subDays($this->window($days));
        $budget = max(1, (int) config('social.lead_ads.backfill_max_leads', 100));
        $forms = LeadForm::query()->where('account_id', $account->id)->where('is_enabled', true)->orderBy('name')->get();

        foreach ($forms as $form) {
            if ($budget <= 0) {
                $summary['stopped'] = 'limit_reached';
                break;
            }

            try {
                $leads = $this->graph->formLeads($form->external_id, (string) $account->access_token, $since, $budget);
            } catch (LeadAdsGraphException $exception) {
                if ($exception->kind === LeadAdsGraphException::TOKEN) {
                    $this->accounts->markNeedsReauthorization($account, $exception->getMessage());
                }

                $page->forceFill(['last_error_code' => $exception->kind, 'last_error' => Str::limit($exception->getMessage(), 480, '')])->save();
                $summary['stopped'] = $exception->kind;
                break;
            }

            $summary['forms']++;

            foreach ($leads as $data) {
                $created = null;

                if (is_string($data['created_time'] ?? null)) {
                    try {
                        $created = CarbonImmutable::parse($data['created_time']);
                    } catch (\Throwable) {
                        $created = null;
                    }
                }

                $leadgenId = (string) $data['id'];

                if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $leadgenId) !== 1) {
                    continue;
                }

                $row = $this->ingest->register(
                    $page,
                    $leadgenId,
                    $form->external_id,
                    is_string($data['ad_id'] ?? null) ? $data['ad_id'] : null,
                    $created,
                    'backfill',
                    $this->processor->payloadFrom($data),
                );

                $row === null ? $summary['known']++ : $summary['new']++;
                $budget--;
            }
        }

        $page->forceFill(['last_backfill_at' => now()])->save();

        return $summary;
    }
}
