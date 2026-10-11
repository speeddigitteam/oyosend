<?php

declare(strict_types=1);

namespace App\Modules\Projects\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Projects\Models\ProjectCategory;
use App\Modules\Projects\Models\ProjectTemplate;
use Illuminate\Support\Facades\DB;

final class ProjectSettingsManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $attributes */
    public function createCategory(array $attributes): ProjectCategory
    {
        return DB::transaction(function () use ($attributes): ProjectCategory {
            /** @var ProjectCategory $category */
            $category = ProjectCategory::query()->create($attributes);
            $this->audit->record('projects.category.created', $category);

            return $category;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function createTemplate(array $attributes): ProjectTemplate
    {
        return DB::transaction(function () use ($attributes): ProjectTemplate {
            if (($attributes['category_id'] ?? null) !== null) {
                ProjectCategory::query()->findOrFail($attributes['category_id']);
            }

            /** @var ProjectTemplate $template */
            $template = ProjectTemplate::query()->create($attributes);
            $this->audit->record('projects.template.created', $template, ['task_count' => count($template->task_blueprints ?? [])]);

            return $template;
        });
    }
}
