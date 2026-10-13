<?php

declare(strict_types=1);

namespace App\Core\Crm\Data;

enum AudienceSegmentKind: string
{
    case LeadStatus = 'lead_status';
    case DealStage = 'deal_stage';
    case Tag = 'tag';
    case SavedSegment = 'saved_segment';
    case CustomerLifecycle = 'customer_lifecycle';
}
