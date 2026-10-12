<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ChecklistKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property ChecklistKind $kind
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChecklistTemplate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_checklist_templates';

    /** @var list<string> */
    protected $fillable = ['name', 'kind', 'is_default', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ChecklistKind::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<TemplateTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(TemplateTask::class, 'template_id')->orderBy('position');
    }

    public function auditLabel(): string
    {
        return (string) ($this->name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.template.'.$verb;
    }
}
