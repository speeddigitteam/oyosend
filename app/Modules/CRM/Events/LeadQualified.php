<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Lead;

final class LeadQualified extends TenantDomainEvent
{
    public readonly string $leadId;

    public readonly int $score;

    public function __construct(Lead $lead)
    {
        parent::__construct();
        $this->leadId = $lead->id;
        $this->score = $lead->score;
    }

    public static function name(): string
    {
        return 'crm.lead.qualified';
    }

    public function payload(): array
    {
        return ['lead_id' => $this->leadId, 'score' => $this->score];
    }
}
