<?php

declare(strict_types=1);

namespace App\Modules\Support\Enums;

enum TicketEventKind: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Assigned = 'assigned';
    case Unassigned = 'unassigned';
    case StatusChanged = 'status_changed';
    case PriorityChanged = 'priority_changed';
    case QueueChanged = 'queue_changed';
    case Replied = 'replied';
    case Noted = 'noted';
    case CustomerReplied = 'customer_replied';
    case MergedInto = 'merged_into';
    case MergedFrom = 'merged_from';
    case SplitFrom = 'split_from';
    case SplitTo = 'split_to';
    case SlaPaused = 'sla_paused';
    case SlaResumed = 'sla_resumed';
    case SlaBreached = 'sla_breached';
    case SurveyRequested = 'survey_requested';
    case SurveyAnswered = 'survey_answered';
}
