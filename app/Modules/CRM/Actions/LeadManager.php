<?php

declare(strict_types=1);

namespace App\Modules\CRM\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Modules\CRM\Enums\LeadStatus;
use App\Modules\CRM\Events\LeadCreated;
use App\Modules\CRM\Events\LeadQualified;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeadManager
{
    public function __construct(private readonly AuditLogger $audit, private readonly TimelineRecorder $timeline) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, ?TenantMembership $owner = null): Lead
    {
        return DB::transaction(function () use ($attributes, $owner): Lead {
            if (($attributes['contact_id'] ?? null) === null && ($attributes['company_id'] ?? null) === null) {
                throw ValidationException::withMessages(['contact_id' => __('Choose a contact or company for the lead.')]);
            }

            $this->guardReferences($attributes);

            $lead = new Lead(['status' => LeadStatus::New, 'score' => 0, ...$attributes]);
            $lead->assignOwner($owner)->save();
            $this->timeline->record('crm.lead.created', __('Lead created.'), $this->links($lead), $owner);
            $this->audit->record('crm.lead.created', $lead, ['status' => $lead->status->value, 'score' => $lead->score]);
            LeadCreated::dispatch($lead);

            return $lead;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Lead $lead, array $attributes, ?TenantMembership $actor = null): Lead
    {
        return DB::transaction(function () use ($lead, $attributes, $actor): Lead {
            $this->guardLifecycleStatus($lead, $attributes);

            $contactId = array_key_exists('contact_id', $attributes) ? $attributes['contact_id'] : $lead->contact_id;
            $companyId = array_key_exists('company_id', $attributes) ? $attributes['company_id'] : $lead->company_id;

            if ($contactId === null && $companyId === null) {
                throw ValidationException::withMessages(['contact_id' => __('Choose a contact or company for the lead.')]);
            }

            $this->guardReferences($attributes);
            $lead->fill($attributes);

            if ($lead->isDirty()) {
                $changed = array_keys($lead->getDirty());
                $lead->save();
                $this->timeline->record('crm.lead.updated', __('Lead details updated.'), $this->links($lead), $actor, ['changed' => implode(',', $changed)]);
                $this->audit->record('crm.lead.updated', $lead, ['changed' => $changed]);
            }

            return $lead;
        });
    }

    /** @param array<string, mixed> $attributes */
    private function guardLifecycleStatus(Lead $lead, array $attributes): void
    {
        if (! array_key_exists('status', $attributes)) {
            return;
        }

        $requested = $attributes['status'] instanceof LeadStatus
            ? $attributes['status']
            : LeadStatus::tryFrom((string) $attributes['status']);

        if ($requested === null
            || (in_array($requested, [LeadStatus::Qualified, LeadStatus::Converted], true) && $requested !== $lead->status)
            || (in_array($lead->status, [LeadStatus::Qualified, LeadStatus::Converted], true) && $requested !== $lead->status)) {
            throw ValidationException::withMessages([
                'status' => __('Qualified and converted lead states can only change through their dedicated workflow.'),
            ]);
        }
    }

    public function score(Lead $lead, int $score, ?TenantMembership $actor = null): Lead
    {
        if ($score < 0 || $score > 100) {
            throw ValidationException::withMessages(['score' => __('Lead score must be between 0 and 100.')]);
        }

        return DB::transaction(function () use ($lead, $score, $actor): Lead {
            $old = $lead->score;
            $lead->update(['score' => $score]);
            $this->timeline->record('crm.lead.scored', __('Lead score changed.'), $this->links($lead), $actor, ['from' => $old, 'to' => $score]);
            $this->audit->record('crm.lead.scored', $lead, ['from' => $old, 'to' => $score]);

            return $lead;
        });
    }

    public function qualify(Lead $lead, int $score, ?string $notes, ?TenantMembership $actor = null): Lead
    {
        return DB::transaction(function () use ($lead, $score, $notes, $actor): Lead {
            if ($lead->status === LeadStatus::Converted) {
                throw ValidationException::withMessages(['status' => __('A converted lead cannot be qualified again.')]);
            }

            $this->score($lead, $score, $actor);
            $lead->update(['status' => LeadStatus::Qualified, 'qualification_notes' => $notes, 'qualified_at' => now()]);
            $this->timeline->record('crm.lead.qualified', __('Lead qualified.'), $this->links($lead), $actor, ['score' => $score]);
            $this->audit->record('crm.lead.qualified', $lead, ['score' => $score]);
            LeadQualified::dispatch($lead);

            return $lead;
        });
    }

    public function assign(Lead $lead, ?TenantMembership $owner, ?TenantMembership $actor = null): Lead
    {
        return DB::transaction(function () use ($lead, $owner, $actor): Lead {
            $from = $lead->owner_id;

            if ($from === $owner?->id) {
                return $lead;
            }

            $lead->assignOwner($owner)->save();
            $this->timeline->record('crm.lead.assigned', __('Lead assignment changed.'), $this->links($lead), $actor, ['from' => $from, 'to' => $owner?->id]);
            $this->audit->record('crm.lead.assigned', $lead, ['from' => $from, 'to' => $owner?->id]);

            return $lead;
        });
    }

    /** @return array{contact_id: string|null, company_id: string|null, lead_id: string} */
    private function links(Lead $lead): array
    {
        return ['contact_id' => $lead->contact_id, 'company_id' => $lead->company_id, 'lead_id' => $lead->id];
    }

    /** @param array<string, mixed> $attributes */
    private function guardReferences(array $attributes): void
    {
        if (($attributes['contact_id'] ?? null) !== null) {
            Contact::query()->findOrFail($attributes['contact_id']);
        }

        if (($attributes['company_id'] ?? null) !== null) {
            Company::query()->findOrFail($attributes['company_id']);
        }

        if (($attributes['source_id'] ?? null) !== null) {
            LeadSource::query()->findOrFail($attributes['source_id']);
        }
    }
}
