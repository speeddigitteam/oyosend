<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\CRM\Models\Activity;

final class ActivityCompleted extends TenantDomainEvent
{
    public readonly string $activityId;

    public readonly string $subject;

    public function __construct(Activity $activity)
    {
        parent::__construct();
        $this->activityId = $activity->id;
        $this->subject = $activity->subject;
    }

    public static function name(): string
    {
        return 'crm.activity.completed';
    }

    public function payload(): array
    {
        return ['activity_id' => $this->activityId, 'subject' => $this->subject];
    }
}
