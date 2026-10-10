<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $pipeline_id
 * @property string $name
 * @property int $probability
 * @property int $position
 * @property bool $is_won
 * @property bool $is_lost
 */
class PipelineStage extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_pipeline_stages';

    protected $fillable = ['pipeline_id', 'name', 'probability', 'position', 'is_won', 'is_lost'];

    protected function casts(): array
    {
        return ['probability' => 'integer', 'position' => 'integer', 'is_won' => 'boolean', 'is_lost' => 'boolean'];
    }

    /** @return BelongsTo<Pipeline, $this> */
    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(Pipeline::class);
    }

    /** @return HasMany<Deal, $this> */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
