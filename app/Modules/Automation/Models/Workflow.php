<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Automation\Enums\VersionStatus;
use App\Modules\Automation\Enums\WorkflowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A named automation. Its logic lives in versions; this row says which version is live, who the workflow
 * runs as, and the few facts needed to match a trigger quickly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property WorkflowStatus $status
 * @property string|null $actor_membership_id
 * @property string|null $created_by_membership_id
 * @property string|null $published_version_id
 * @property string|null $trigger_key
 * @property array<string, mixed>|null $trigger_config
 * @property string|null $webhook_token_hash
 * @property string|null $webhook_secret
 * @property Carbon|null $next_run_at
 * @property Carbon|null $last_run_at
 * @property string|null $last_run_status
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Workflow extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_workflows';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'status', 'actor_membership_id', 'created_by_membership_id'];

    /** @var list<string> */
    protected $hidden = ['webhook_token_hash', 'webhook_secret'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => WorkflowStatus::class,
            'trigger_config' => 'array',
            'webhook_secret' => 'encrypted',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class, 'workflow_id');
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class, 'workflow_id');
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'published_version_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_membership_id');
    }

    public function draft(): ?WorkflowVersion
    {
        return $this->versions()->where('status', VersionStatus::Draft->value)->first();
    }

    public function isLive(): bool
    {
        return $this->status === WorkflowStatus::Active && $this->published_version_id !== null;
    }
}
