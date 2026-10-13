<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds;

use App\Core\Crm\Contracts\LeadIntake;
use App\Core\Crm\Data\LeadCapture;
use App\Core\Crm\Data\LeadCaptureStatus;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\Automation\LeadAdReceived;
use App\Modules\Social\LeadAds\Jobs\ProcessFacebookLead;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraph;
use App\Modules\Social\Platforms\Facebook\LeadAdsGraphException;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Takes one received lead to its outcome: fetch its answers with the Page token, map them with the form's
 * mapping and hand them to the CRM through the `LeadIntake` contract (idempotency key `fb-lead:{leadgen_id}`).
 *
 * Every exit leaves the row in an honest state with a reason: captured, duplicate, failed, waiting for the CRM,
 * waiting for the Page to be connected again, or skipped. The CRM is only ever reached through the contract.
 */
final class LeadProcessor
{
    public const string SOURCE = 'facebook_lead_ads';

    public function __construct(
        private readonly LeadAdsGraph $graph,
        private readonly LeadIntake $intake,
        private readonly FieldMapper $mapper,
        private readonly AccountManager $accounts,
        private readonly LeadTrail $trail,
    ) {}

    /** Process one lead now, unless another worker holds it or its retry time has not come. */
    public function process(string $leadId, bool $force = false): void
    {
        $claimed = LeadAdLead::query()
            ->whereKey($leadId)
            ->whereIn('status', LeadStatus::open())
            ->where(static fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(5)))
            ->when(! $force, static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->update(['claimed_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $lead = LeadAdLead::query()->findOrFail($leadId);

        try {
            $this->run($lead);
        } finally {
            LeadAdLead::query()->whereKey($leadId)->update(['claimed_at' => null]);
        }
    }

    private function run(LeadAdLead $lead): void
    {
        $account = SocialAccount::query()->find($lead->account_id);

        if ($account === null || $account->status === AccountStatus::Disconnected) {
            $this->finish($lead, LeadStatus::Failed, 'account_disconnected', __('The Facebook Page was disconnected.'), step: LeadTrail::FAILED);

            return;
        }

        $form = $this->form($lead);

        if ($lead->external_form_id !== null && ($form === null || ! $form->is_enabled)) {
            $this->skip($lead, $form);

            return;
        }

        if ($lead->payload === null && ! $this->fetch($lead, $account)) {
            return;
        }

        $lead->refresh();
        $form ??= $this->form($lead);

        if ($form === null || ! $form->is_enabled) {
            $this->skip($lead, $form);

            return;
        }

        $this->capture($lead, $form);
    }

    private function form(LeadAdLead $lead): ?LeadForm
    {
        if ($lead->form_id !== null) {
            return LeadForm::query()->find($lead->form_id);
        }

        if ($lead->external_form_id === null) {
            return null;
        }

        return LeadForm::query()->where('account_id', $lead->account_id)->where('external_id', $lead->external_form_id)->first();
    }

    private function skip(LeadAdLead $lead, ?LeadForm $form): void
    {
        $this->finish(
            $lead,
            LeadStatus::Skipped,
            $form === null ? 'form_unknown' : 'form_not_enabled',
            $form === null
                ? __('This form is not in the forms list. Refresh the forms, switch it on and retry.')
                : __('The form is not switched on. Switch it on and retry to send this lead to the CRM.'),
            step: LeadTrail::SKIPPED,
        );
    }

    /** @return bool whether the answers are now stored on the lead */
    private function fetch(LeadAdLead $lead, SocialAccount $account): bool
    {
        if ($account->status !== AccountStatus::Connected || $account->access_token === null) {
            $this->finish($lead, LeadStatus::WaitingForAccount, 'reauthorization_required', __('The Page must be connected again. This lead is fetched as soon as it is.'), step: LeadTrail::WAITING);

            return false;
        }

        try {
            $data = $this->graph->lead($lead->leadgen_id, (string) $account->access_token);
        } catch (LeadAdsGraphException $exception) {
            $this->graphFailure($lead, $account, $exception);

            return false;
        }

        $adId = is_string($data['ad_id'] ?? null) ? mb_substr($data['ad_id'], 0, 64) : $lead->ad_id;
        $formId = is_string($data['form_id'] ?? null) ? mb_substr($data['form_id'], 0, 64) : $lead->external_form_id;
        $created = is_string($data['created_time'] ?? null) ? $this->time($data['created_time']) : null;

        $lead->forceFill([
            'payload' => $this->payload($data),
            'ad_id' => $adId,
            'external_form_id' => $formId,
            'campaign_ref' => is_string($data['campaign_id'] ?? null) ? mb_substr($data['campaign_id'], 0, 120) : $lead->campaign_ref,
            'created_time_at' => $created ?? $lead->created_time_at,
            'fetched_at' => now(),
            'attempts' => $lead->attempts + 1,
        ]);

        if ($lead->form_id === null && $formId !== null) {
            $lead->form_id = LeadForm::query()->where('account_id', $lead->account_id)->where('external_id', $formId)->value('id');
        }

        $lead->save();
        $this->trail->add($lead, LeadTrail::FETCHED, null, __('The lead answers were fetched from Facebook.'));

        return true;
    }

    private function capture(LeadAdLead $lead, LeadForm $form): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $lead->payload ?? [];
        /** @var list<array{name?: mixed, values?: mixed}> $fieldData */
        $fieldData = is_array($payload['field_data'] ?? null) ? array_values($payload['field_data']) : [];
        $mapping = $this->mapper->resolve($form->questions ?? [], $form->mapping);
        $mapped = $this->mapper->map($fieldData, $mapping);

        $problem = $mapped->problem();

        if ($problem !== null) {
            $this->finish(
                $lead,
                LeadStatus::Failed,
                $problem,
                $problem === 'missing_contact'
                    ? __('The lead has no email address or phone number mapped. Map the form questions, then retry.')
                    : __('The email address or phone number in this lead is not usable, and there is no other way to reach it.'),
                step: LeadTrail::FAILED,
            );

            return;
        }

        if ($mapped->emailRejected && $mapped->email === null) {
            $this->trail->add($lead, LeadTrail::NOTICE, 'email_ignored', __('The email address was not valid and was left out.'));
        }

        if ($mapped->phoneRejected && $mapped->phone === null) {
            $this->trail->add($lead, LeadTrail::NOTICE, 'phone_ignored', __('The phone number was not valid and was left out.'));
        }

        $answers = $mapped->answers;
        $answers['facebook_form'] = $form->name;

        try {
            $capture = new LeadCapture(
                source: self::SOURCE,
                idempotencyKey: 'fb-lead:'.$lead->leadgen_id,
                name: $mapped->name,
                email: $mapped->email,
                phone: $mapped->phone,
                companyName: $mapped->company,
                answers: $answers,
                campaignRef: $lead->campaign_ref,
                adRef: $lead->ad_id,
                consentEvidence: $mapped->consentEvidence,
                occurredAt: $lead->created_time_at === null ? null : CarbonImmutable::instance($lead->created_time_at),
            );
        } catch (InvalidArgumentException) {
            $this->finish($lead, LeadStatus::Failed, 'invalid_contact', __('The email address or phone number in this lead is not usable.'), step: LeadTrail::FAILED);

            return;
        }

        $result = $this->intake->capture($capture);

        if ($result->status === LeadCaptureStatus::Unavailable) {
            $this->finish($lead, LeadStatus::WaitingForCrm, 'crm_unavailable', __('The CRM is not available in this workspace. The lead is kept and sent as soon as it is.'), step: LeadTrail::WAITING, keepPayload: true);

            return;
        }

        $lead->forceFill([
            'crm_contact_id' => $result->contactId,
            'crm_lead_id' => $result->leadId,
        ]);

        if ($result->isReplay()) {
            $this->finish($lead, LeadStatus::Duplicate, 'already_captured', __('The CRM already had this lead.'), step: LeadTrail::DUPLICATE);

            return;
        }

        $this->finish($lead, LeadStatus::Captured, null, __('The lead was handed to the CRM.'), step: LeadTrail::CAPTURED);
        $form->forceFill(['last_lead_at' => now()])->save();
        event(LeadAdReceived::from($lead));
    }

    private function graphFailure(LeadAdLead $lead, SocialAccount $account, LeadAdsGraphException $exception): void
    {
        $max = (int) config('social.lead_ads.max_attempts', 6);
        $backoff = array_values(array_map('intval', (array) config('social.lead_ads.backoff_seconds', [60, 300, 1800])));
        $tooOld = $lead->received_at->lt(now()->subDays((int) config('social.lead_ads.max_age_days', 3)));

        switch ($exception->kind) {
            case LeadAdsGraphException::TOKEN:
                $this->accounts->markNeedsReauthorization($account, $exception->getMessage());
                $this->finish($lead, LeadStatus::WaitingForAccount, 'reauthorization_required', $exception->getMessage(), step: LeadTrail::WAITING);

                return;
            case LeadAdsGraphException::PERMISSION:
                $this->finish($lead, LeadStatus::Failed, 'permission_missing', $exception->getMessage(), step: LeadTrail::FAILED);

                return;
            case LeadAdsGraphException::NOT_FOUND:
                $this->finish($lead, LeadStatus::Failed, 'lead_not_found', $exception->getMessage(), step: LeadTrail::FAILED);

                return;
            case LeadAdsGraphException::RATE_LIMIT:
                // Throttling is not the lead's fault: it does not spend an attempt, only its age counts.
                if ($tooOld) {
                    $this->finish($lead, LeadStatus::Failed, 'expired', __('Facebook kept limiting requests and this lead was not fetched in time.'), step: LeadTrail::FAILED);

                    return;
                }

                $this->retryLater($lead, max(30, $exception->retryAfterSeconds ?? $backoff[0] ?? 60), 'rate_limited', $exception->getMessage());

                return;
            case LeadAdsGraphException::TRANSIENT:
                $attempts = $lead->attempts + 1;

                if ($attempts >= $max || $tooOld) {
                    $this->finish($lead, LeadStatus::Failed, 'gave_up', __('Facebook could not be reached after several tries. Retry the lead later.'), step: LeadTrail::FAILED, attempts: $attempts);

                    return;
                }

                $this->retryLater($lead, $backoff[min($attempts - 1, max(0, count($backoff) - 1))] ?? 60, 'transient', $exception->getMessage(), $attempts);

                return;
            default:
                $this->finish($lead, LeadStatus::Failed, 'rejected', $exception->getMessage(), step: LeadTrail::FAILED, attempts: $lead->attempts + 1);
        }
    }

    private function retryLater(LeadAdLead $lead, int $seconds, string $code, string $message, ?int $attempts = null): void
    {
        $lead->forceFill([
            'status' => LeadStatus::Received,
            'next_attempt_at' => now()->addSeconds($seconds),
            'reason_code' => $code,
            'reason' => $message,
            'attempts' => $attempts ?? $lead->attempts,
        ])->save();

        $this->trail->add($lead, LeadTrail::RETRY, $code, $message);
        ProcessFacebookLead::dispatch($lead->id)->delay(now()->addSeconds($seconds));
    }

    private function finish(
        LeadAdLead $lead,
        LeadStatus $status,
        ?string $code,
        string $message,
        string $step,
        bool $keepPayload = true,
        ?int $attempts = null,
    ): void {
        $final = $status->isFinal();

        $lead->forceFill([
            'status' => $status,
            'reason_code' => $code,
            'reason' => $final ? null : $message,
            'next_attempt_at' => null,
            'processed_at' => $final || $status === LeadStatus::Failed ? now() : $lead->processed_at,
            'attempts' => $attempts ?? $lead->attempts,
            // Answers are personal data: gone as soon as the CRM has them.
            'payload' => $final || ! $keepPayload ? null : $lead->payload,
        ])->save();

        $this->trail->add($lead, $step, $code, $message);
    }

    /**
     * Only the fields the processor needs, never the platform's full body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function payload(array $data): array
    {
        $fieldData = [];

        foreach (is_array($data['field_data'] ?? null) ? $data['field_data'] : [] as $entry) {
            if (count($fieldData) >= 60) {
                break;
            }

            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $values = array_values(array_filter(
                    array_map(static fn ($value): string => is_scalar($value) ? mb_substr((string) $value, 0, 1000) : '', array_slice((array) ($entry['values'] ?? []), 0, 10)),
                    static fn (string $value): bool => $value !== '',
                ));
                $fieldData[] = ['name' => mb_substr($entry['name'], 0, 64), 'values' => $values];
            }
        }

        return [
            'field_data' => $fieldData,
            'campaign_id' => is_string($data['campaign_id'] ?? null) ? mb_substr($data['campaign_id'], 0, 120) : null,
            'ad_id' => is_string($data['ad_id'] ?? null) ? mb_substr($data['ad_id'], 0, 64) : null,
            'form_id' => is_string($data['form_id'] ?? null) ? mb_substr($data['form_id'], 0, 64) : null,
            'created_time' => is_string($data['created_time'] ?? null) ? $data['created_time'] : null,
        ];
    }

    private function time(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalises a list item (backfill) into the stored payload shape.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function payloadFrom(array $data): array
    {
        return $this->payload($data);
    }
}
