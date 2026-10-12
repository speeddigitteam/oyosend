<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\StageType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $pipeline_id
 * @property string $name
 * @property int $position
 * @property StageType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PipelineStage extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_pipeline_stages';

    /** @var list<string> */
    protected $fillable = ['pipeline_id', 'name', 'position', 'type'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'type' => StageType::class,
        ];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }

    public function auditLabel(): string
    {
        return (string) ($this->name);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.pipeline_stage.'.$verb;
    }
}
