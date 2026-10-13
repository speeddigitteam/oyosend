<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Modules\Social\LeadAds\Jobs\ProcessFacebookLead;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Registers a lead the platform announced (webhook) or listed (backfill) exactly once per `leadgen_id` in the
 * workspace, then queues its processing. A second delivery of the same lead is a no-op.
 */
final class LeadIngest
{
    public function __construct(private readonly LeadTrail $trail) {}

    /**
     * @param  array<string, mixed>|null  $payload  answers already fetched (backfill); null = fetch when processed
     * @return LeadAdLead|null the new row, or null when this lead was already registered
     */
    public function register(
        LeadAdsPage $page,
        string $leadgenId,
        ?string $formId,
        ?string $adId,
        ?CarbonImmutable $createdTime,
        string $origin,
        ?array $payload = null,
    ): ?LeadAdLead {
        $form = $formId === null ? null : LeadForm::query()->where('account_id', $page->account_id)->where('external_id', $formId)->first();

        try {
            $lead = LeadAdLead::query()->create([
                'account_id' => $page->account_id,
                'form_id' => $form?->id,
                'leadgen_id' => $leadgenId,
                'page_id' => $page->page_id,
                'external_form_id' => $formId,
                'ad_id' => $adId === null ? null : mb_substr($adId, 0, 64),
                'campaign_ref' => is_string($payload['campaign_id'] ?? null) ? mb_substr($payload['campaign_id'], 0, 120) : null,
                'origin' => $origin,
                'status' => LeadStatus::Received,
                'payload' => $payload,
                'created_time_at' => $createdTime,
                'received_at' => now(),
                'fetched_at' => $payload === null ? null : now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $this->trail->add($lead, LeadTrail::RECEIVED, $origin, $origin === 'backfill'
            ? __('Listed by a manual fetch of recent leads.')
            : __('Delivered by the Facebook webhook.'));

        ProcessFacebookLead::dispatch($lead->id);

        return $lead;
    }
}
