<?php

declare(strict_types=1);

namespace App\Modules\Projects\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Projects\Models\Project;

final class ProjectCreated extends TenantDomainEvent
{
    public function __construct(
        public readonly string $projectId,
        public readonly string $name,
        public readonly ?string $contactId,
        public readonly ?string $companyId,
        public readonly ?string $ownerId,
    ) {
        parent::__construct();
    }

    public static function from(Project $project): self
    {
        return new self($project->id, $project->name, $project->contact_id, $project->company_id, $project->owner_id);
    }

    public static function name(): string
    {
        return 'projects.project.created';
    }

    public function payload(): array
    {
        return ['project_id' => $this->projectId, 'name' => $this->name, 'contact_id' => $this->contactId, 'company_id' => $this->companyId, 'owner_id' => $this->ownerId];
    }
}
