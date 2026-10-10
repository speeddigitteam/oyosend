<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $deal_id
 * @property string|null $from_stage_id
 * @property string $to_stage_id
 * @property string|null $changed_by
 * @property string|null $note
 * @property Carbon $changed_at
 * @property-read PipelineStage|null $fromStage
 * @property-read PipelineStage $toStage
 * @property-read TenantMembership|null $changedBy
 */
class DealStageHistory extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'crm_deal_stage_history';

    protected $fillable = ['deal_id', 'from_stage_id', 'to_stage_id', 'changed_by', 'note', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    /** @return BelongsTo<PipelineStage, $this> */
    public function toStage(): BelongsTo
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'changed_by');
    }
}
