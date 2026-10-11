<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Projects\Enums\EntryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $project_id
 * @property string|null $task_id
 * @property string $membership_id
 * @property Carbon $worked_on
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int $minutes
 * @property string|null $description
 * @property bool $is_billable
 * @property string $currency
 * @property int $billable_rate_amount
 * @property int $cost_rate_amount
 * @property EntryStatus $status
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read TenantMembership $membership
 */
class TimeEntry extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'project_time_entries';

    protected $fillable = ['project_id', 'task_id', 'membership_id', 'worked_on', 'started_at', 'ended_at', 'minutes', 'description', 'is_billable', 'currency', 'billable_rate_amount', 'cost_rate_amount', 'status', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['worked_on' => 'date', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'minutes' => 'integer', 'is_billable' => 'boolean', 'billable_rate_amount' => 'integer', 'cost_rate_amount' => 'integer', 'status' => EntryStatus::class, 'approved_at' => 'datetime'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }
}
