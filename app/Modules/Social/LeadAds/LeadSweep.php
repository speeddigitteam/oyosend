<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\Jobs\ProcessFacebookLead;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;

/**
 * The every-minute safety net of lead capture, entitlement-gated by the runner:
 *
 *  - leads whose retry time has come, or whose job was lost, are queued again;
 *  - leads waiting for the CRM are sent once the workspace has it;
 *  - leads waiting for a Page connection are fetched once the Page is connected again;
 *  - hourly: forms are re-listed when stale, answers are removed after the retention window, old history is deleted.
 */
final class LeadSweep
{
    public function __construct(
        private readonly LeadAdsAvailability $availability,
        private readonly LeadAdsSetup $setup,
        private readonly LeadTrail $trail,
    ) {}

    public function run(): void
    {
        $batch = max(1, (int) config('social.lead_ads.sweep_batch', 50));

        if (! LeadAdLead::query()->whereIn('status', LeadStatus::open())->exists() && ! $this->housekeepingDue()) {
            return;
        }

        $this->queueDue($batch);
        $this->queueWaitingForCrm($batch);
        $this->queueWaitingForAccount($batch);

        if ($this->housekeepingDue(claim: true)) {
            $this->refreshForms();
            $this->purge();
        }
    }

    private function queueDue(int $batch): void
    {
        $ids = LeadAdLead::query()
            ->where('status', LeadStatus::Received->value)
            ->where(static fn ($query) => $query
                ->where('next_attempt_at', '<=', now())
                // A delivery whose job was lost: nothing scheduled and not touched for a few minutes.
                ->orWhere(static fn ($inner) => $inner->whereNull('next_attempt_at')->where('updated_at', '<', now()->subMinutes(3))))
            ->orderBy('received_at')
            ->limit($batch)
            ->pluck('id');

        foreach ($ids as $id) {
            ProcessFacebookLead::dispatch((string) $id);
        }
    }

    private function queueWaitingForCrm(int $batch): void
    {
        if (! $this->availability->crmAvailable()) {
            return;
        }

        $ids = LeadAdLead::query()->where('status', LeadStatus::WaitingForCrm->value)->orderBy('received_at')->limit($batch)->pluck('id');

        foreach ($ids as $id) {
            ProcessFacebookLead::dispatch((string) $id, true);
        }
    }

    private function queueWaitingForAccount(int $batch): void
    {
        $waiting = LeadAdLead::query()->where('status', LeadStatus::WaitingForAccount->value)->orderBy('received_at')->limit($batch)->get(['id', 'account_id']);

        if ($waiting->isEmpty()) {
            return;
        }

        $connected = SocialAccount::query()
            ->whereIn('id', $waiting->pluck('account_id')->unique()->all())
            ->where('status', AccountStatus::Connected->value)
            ->pluck('id')->all();

        foreach ($waiting as $lead) {
            if (in_array($lead->account_id, $connected, true)) {
                ProcessFacebookLead::dispatch($lead->id, true);
            }
        }
    }

    private function housekeepingDue(bool $claim = false): bool
    {
        $key = 'social:leadads:housekeeping';

        if ($claim) {
            return Cache::add($key, 1, 3600);
        }

        return ! Cache::has($key);
    }

    private function refreshForms(): void
    {
        $hours = max(1, (int) config('social.lead_ads.forms_refresh_hours', 12));
        $pages = LeadAdsPage::query()
            ->where('state', LeadAdsPage::SUBSCRIBED)
            ->where(static fn ($query) => $query->whereNull('forms_synced_at')->orWhere('forms_synced_at', '<', now()->subHours($hours)))
            ->limit(3)
            ->get();

        foreach ($pages as $page) {
            $account = SocialAccount::query()->find($page->account_id);

            if ($account !== null && $account->status === AccountStatus::Connected) {
                $this->setup->syncForms($account, $page);
            }
        }
    }

    private function purge(): void
    {
        $payloadDays = max(1, (int) config('social.lead_ads.payload_retention_days', 14));
        $retentionDays = max($payloadDays, (int) config('social.lead_ads.retention_days', 180));

        // A lead still waiting past the window can no longer be sent: its answers are removed, honestly.
        $stale = LeadAdLead::query()
            ->whereIn('status', [LeadStatus::WaitingForCrm->value, LeadStatus::WaitingForAccount->value, LeadStatus::Received->value])
            ->where('received_at', '<', now()->subDays($payloadDays))
            ->limit(200)
            ->get();

        foreach ($stale as $lead) {
            $lead->forceFill([
                'status' => LeadStatus::Failed,
                'reason_code' => 'expired',
                'reason' => __('The lead was not handed over within :days days and its answers were removed.', ['days' => $payloadDays]),
                'payload' => null,
                'next_attempt_at' => null,
                'processed_at' => now(),
            ])->save();
            $this->trail->add($lead, LeadTrail::FAILED, 'expired', (string) $lead->reason);
        }

        LeadAdLead::query()
            ->whereNotNull('payload')
            ->whereIn('status', [LeadStatus::Failed->value, LeadStatus::Skipped->value])
            ->where('received_at', '<', now()->subDays($payloadDays))
            ->update(['payload' => null]);

        LeadAdLead::query()->where('received_at', '<', now()->subDays($retentionDays))->limit(500)->delete();
    }
}
