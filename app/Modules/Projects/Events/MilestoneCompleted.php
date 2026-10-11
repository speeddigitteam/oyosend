<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Projects\Models\Milestone;

final class MilestoneCompleted extends TenantDomainEvent
{
    public function __construct(public readonly string $milestoneId, public readonly string $projectId, public readonly string $name)
    {
        parent::__construct();
    }

    public static function from(Milestone $milestone): self
    {
        return new self($milestone->id, $milestone->project_id, $milestone->name);
    }

    public static function name(): string
    {
        return 'projects.milestone.completed';
    }

    public function payload(): array
    {
        return ['milestone_id' => $this->milestoneId, 'project_id' => $this->projectId, 'name' => $this->name];
    }
}
