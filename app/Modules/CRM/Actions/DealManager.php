<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Enums\DealStatus;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Events\DealCreated;
use App\Modules\CRM\Events\DealStageChanged;
use App\Modules\CRM\Events\DealWon;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealStageHistory;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\Pipeline;
use App\Modules\CRM\Models\PipelineStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DealManager
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TimelineRecorder $timeline,
        private readonly CustomerLifecycleManager $customers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, Pipeline $pipeline, PipelineStage $stage, ?TenantMembership $owner = null, ?TenantMembership $actor = null): Deal
    {
        return DB::transaction(function () use ($attributes, $pipeline, $stage, $owner, $actor): Deal {
            $this->guardStage($pipeline, $stage);
            $attributes['amount'] = $this->amount($attributes['amount'] ?? 0);
            $lead = isset($attributes['lead_id']) ? Lead::query()->findOrFail($attributes['lead_id']) : null;
            $attributes['contact_id'] ??= $lead?->contact_id;
            $attributes['company_id'] ??= $lead?->company_id;

            if ($lead === null && ($attributes['contact_id'] ?? null) === null && ($attributes['company_id'] ?? null) === null) {
                throw ValidationException::withMessages(['contact_id' => __('Choose a lead, contact or company for the deal.')]);
            }

            if (($attributes['contact_id'] ?? null) !== null) {
                Contact::query()->findOrFail($attributes['contact_id']);
            }

            if (($attributes['company_id'] ?? null) !== null) {
                Company::query()->findOrFail($attributes['company_id']);
            }

            $status = $this->statusFor($stage);
            $dealOwner = $owner;

            if ($dealOwner === null && $lead?->owner_id !== null) {
                $dealOwner = TenantMembership::query()->find($lead->owner_id);
            }

            $deal = new Deal([
                ...$attributes,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'status' => $status,
                'won_at' => $status === DealStatus::Won ? now() : null,
                'lost_at' => $status === DealStatus::Lost ? now() : null,
            ]);
            $deal->assignOwner($dealOwner)->save();
            DealStageHistory::query()->create(['deal_id' => $deal->id, 'to_stage_id' => $stage->id, 'changed_by' => $actor?->id, 'changed_at' => now()]);

            if ($lead !== null && $lead->status !== LeadStatus::Converted) {
                $lead->update(['status' => LeadStatus::Converted, 'converted_at' => now()]);
                $this->audit->record('crm.lead.converted', $lead, ['deal_id' => $deal->id]);
            }

            $this->timeline->record('crm.deal.created', __('Deal created.'), $this->links($deal), $actor, ['stage_id' => $stage->id]);
            $this->audit->record('crm.deal.created', $deal, ['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'amount' => $deal->amount, 'currency' => $deal->currency]);
            DealCreated::dispatch($deal);

            if ($status === DealStatus::Won) {
                $this->customers->recordWon($deal->contact_id, $deal->company_id, $deal->amount, $deal->currency, $deal->won_at ?? now(), $dealOwner);
                DealWon::dispatch($deal);
            }

            return $deal;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Deal $deal, array $attributes, ?TenantMembership $actor = null): Deal
    {
        return DB::transaction(function () use ($deal, $attributes, $actor): Deal {
            /** @var Deal $deal */
            $deal = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());

            if ($deal->status !== DealStatus::Open) {
                throw ValidationException::withMessages(['deal' => __('A closed deal cannot be edited.')]);
            }

            if (array_key_exists('amount', $attributes)) {
                $attributes['amount'] = $this->amount($attributes['amount']);
            }

            $leadId = array_key_exists('lead_id', $attributes) ? $attributes['lead_id'] : $deal->lead_id;
            $contactId = array_key_exists('contact_id', $attributes) ? $attributes['contact_id'] : $deal->contact_id;
            $companyId = array_key_exists('company_id', $attributes) ? $attributes['company_id'] : $deal->company_id;

            if ($leadId === null && $contactId === null && $companyId === null) {
                throw ValidationException::withMessages(['contact_id' => __('Choose a lead, contact or company for the deal.')]);
            }

            if (($attributes['lead_id'] ?? null) !== null) {
                Lead::query()->findOrFail($attributes['lead_id']);
            }

            if (($attributes['contact_id'] ?? null) !== null) {
                Contact::query()->findOrFail($attributes['contact_id']);
            }

            if (($attributes['company_id'] ?? null) !== null) {
                Company::query()->findOrFail($attributes['company_id']);
            }

            $deal->fill($attributes);

            if ($deal->isDirty()) {
                $changed = array_keys($deal->getDirty());
                $deal->save();
                $this->timeline->record('crm.deal.updated', __('Deal details updated.'), $this->links($deal), $actor, ['changed' => implode(',', $changed)]);
                $this->audit->record('crm.deal.updated', $deal, ['changed' => $changed]);
            }

            return $deal;
        });
    }

    public function move(Deal $deal, PipelineStage $stage, ?TenantMembership $actor = null, ?string $note = null): Deal
    {
        return DB::transaction(function () use ($deal, $stage, $actor, $note): Deal {
            /** @var Deal $deal */
            $deal = Deal::query()->lockForUpdate()->findOrFail($deal->getKey());
            $this->guardStage(Pipeline::query()->findOrFail($deal->pipeline_id), $stage);

            if ($deal->stage_id === $stage->id) {
                return $deal;
            }

            if (in_array($deal->status, [DealStatus::Won, DealStatus::Lost], true)) {
                throw ValidationException::withMessages(['stage_id' => __('A closed deal cannot be moved to another stage.')]);
            }

            $from = $deal->stage_id;
            $status = $this->statusFor($stage);
            $firstWin = $status === DealStatus::Won && ! DealStageHistory::query()
                ->where('deal_id', $deal->id)
                ->whereHas('toStage', fn ($query) => $query->where('is_won', true))
                ->exists();
            $deal->update([
                'stage_id' => $stage->id,
                'status' => $status,
                'won_at' => $status === DealStatus::Won ? now() : null,
                'lost_at' => $status === DealStatus::Lost ? now() : null,
                'lost_reason' => $status === DealStatus::Lost ? $note : null,
            ]);
            DealStageHistory::query()->create(['deal_id' => $deal->id, 'from_stage_id' => $from, 'to_stage_id' => $stage->id, 'changed_by' => $actor?->id, 'note' => $note, 'changed_at' => now()]);
            $this->timeline->record('crm.deal.stage_changed', __('Deal stage changed.'), $this->links($deal), $actor, ['from_stage_id' => $from, 'to_stage_id' => $stage->id, 'status' => $status->value]);
            $this->audit->record('crm.deal.stage_changed', $deal, ['from_stage_id' => $from, 'to_stage_id' => $stage->id, 'status' => $status->value]);
            DealStageChanged::dispatch($deal->id, $from, $stage->id, $status->value);

            if ($firstWin) {
                $owner = $deal->owner_id === null ? null : TenantMembership::query()->find($deal->owner_id);
                $this->customers->recordWon($deal->contact_id, $deal->company_id, $deal->amount, $deal->currency, $deal->won_at ?? now(), $owner);
                DealWon::dispatch($deal);
            }

            return $deal;
        });
    }

    public function assign(Deal $deal, ?TenantMembership $owner, ?TenantMembership $actor = null): Deal
    {
        return DB::transaction(function () use ($deal, $owner, $actor): Deal {
            $from = $deal->owner_id;

            if ($from === $owner?->id) {
                return $deal;
            }

            $deal->assignOwner($owner)->save();
            $this->timeline->record('crm.deal.assigned', __('Deal assignment changed.'), $this->links($deal), $actor, ['from' => $from, 'to' => $owner?->id]);
            $this->audit->record('crm.deal.assigned', $deal, ['from' => $from, 'to' => $owner?->id]);

            return $deal;
        });
    }

    private function guardStage(Pipeline $pipeline, PipelineStage $stage): void
    {
        if ($stage->pipeline_id !== $pipeline->id) {
            throw ValidationException::withMessages(['stage_id' => __('The stage does not belong to the selected pipeline.')]);
        }
    }

    private function statusFor(PipelineStage $stage): DealStatus
    {
        return $stage->is_won ? DealStatus::Won : ($stage->is_lost ? DealStatus::Lost : DealStatus::Open);
    }

    private function amount(mixed $amount): int
    {
        if (! is_int($amount) && ! is_string($amount)) {
            throw ValidationException::withMessages(['amount' => __('The amount is outside the supported range.')]);
        }

        return SalesMath::boundedAmount($amount);
    }

    /** @return array{contact_id: string|null, company_id: string|null, lead_id: string|null, deal_id: string} */
    private function links(Deal $deal): array
    {
        return ['contact_id' => $deal->contact_id, 'company_id' => $deal->company_id, 'lead_id' => $deal->lead_id, 'deal_id' => $deal->id];
    }
}
