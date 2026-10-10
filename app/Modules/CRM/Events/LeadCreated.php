<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Lead;

final class LeadCreated extends TenantDomainEvent
{
    public readonly string $leadId;

    public readonly string $title;

    public readonly ?string $contactId;

    public readonly ?string $companyId;

    public readonly ?string $ownerId;

    public readonly int $score;

    public function __construct(Lead $lead)
    {
        parent::__construct();
        $this->leadId = $lead->id;
        $this->title = $lead->title;
        $this->contactId = $lead->contact_id;
        $this->companyId = $lead->company_id;
        $this->ownerId = $lead->owner_id;
        $this->score = $lead->score;
    }

    public static function name(): string
    {
        return 'crm.lead.created';
    }

    public function payload(): array
    {
        return ['lead_id' => $this->leadId, 'title' => $this->title, 'contact_id' => $this->contactId, 'company_id' => $this->companyId, 'owner_id' => $this->ownerId, 'score' => $this->score];
    }
}
