<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string|null $category_id
 * @property string $name
 * @property string|null $description
 * @property list<array<string, mixed>>|null $task_blueprints
 * @property bool $is_active
 * @property-read ProjectCategory|null $category
 */
class ProjectTemplate extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_templates';

    protected $fillable = ['category_id', 'name', 'description', 'task_blueprints', 'is_active'];

    protected function casts(): array
    {
        return ['task_blueprints' => 'array', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<ProjectCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProjectCategory::class);
    }

    public function auditLabel(): string
    {
        return $this->getAttribute('name');
    }
}
