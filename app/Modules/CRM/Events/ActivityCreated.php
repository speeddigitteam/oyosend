<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Activity;

final class ActivityCreated extends TenantDomainEvent
{
    public readonly string $activityId;

    public readonly string $type;

    public readonly string $subject;

    public readonly ?string $dueAt;

    public function __construct(Activity $activity)
    {
        parent::__construct();
        $this->activityId = $activity->id;
        $this->type = $activity->type->value;
        $this->subject = $activity->subject;
        $this->dueAt = $activity->due_at?->toIso8601String();
    }

    public static function name(): string
    {
        return 'crm.activity.created';
    }

    public function payload(): array
    {
        return ['activity_id' => $this->activityId, 'type' => $this->type, 'subject' => $this->subject, 'due_at' => $this->dueAt];
    }
}
