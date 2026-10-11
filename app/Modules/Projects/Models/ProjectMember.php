<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string $membership_id
 * @property string $role
 * @property int $allocation_percent
 * @property string|null $added_by
 * @property-read Project $project
 * @property-read TenantMembership $membership
 */
class ProjectMember extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_members';

    protected $fillable = ['project_id', 'membership_id', 'role', 'allocation_percent', 'added_by'];

    protected function casts(): array
    {
        return ['allocation_percent' => 'integer'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }
}
