<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\ApprovalEntity;
use App\Modules\Projects\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property ApprovalEntity $entity_type
 * @property string $entity_id
 * @property ApprovalStatus $status
 * @property string|null $pending_key
 * @property string|null $request_note
 * @property string|null $decision_note
 * @property string $requested_by
 * @property string $approver_id
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property-read Project $project
 * @property-read TenantMembership $requester
 * @property-read TenantMembership $approver
 */
class Approval extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_approvals';

    protected $fillable = ['project_id', 'entity_type', 'entity_id', 'status', 'pending_key', 'request_note', 'decision_note', 'requested_by', 'approver_id', 'decided_at'];

    protected function casts(): array
    {
        return ['entity_type' => ApprovalEntity::class, 'status' => ApprovalStatus::class, 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'requested_by');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'approver_id');
    }
}
