<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\AssigneeRole;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $template_id
 * @property string $title
 * @property string|null $description
 * @property int $position
 * @property AssigneeRole $assignee_role
 * @property string|null $assignee_employee_id
 * @property int $due_offset_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TemplateTask extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_template_tasks';

    /** @var list<string> */
    protected $fillable = ['template_id', 'title', 'description', 'position', 'assignee_role', 'assignee_employee_id', 'due_offset_days'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'assignee_role' => AssigneeRole::class,
            'due_offset_days' => 'integer',
        ];
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'template_id');
    }
}
