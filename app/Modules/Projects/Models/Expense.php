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
 * @property string $description
 * @property Carbon $incurred_on
 * @property string $currency
 * @property int $amount
 * @property bool $is_billable
 * @property EntryStatus $status
 * @property string $submitted_by
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property-read Project $project
 * @property-read Task|null $task
 * @property-read TenantMembership $submitter
 */
class Expense extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'project_expenses';

    protected $fillable = ['project_id', 'task_id', 'description', 'incurred_on', 'currency', 'amount', 'is_billable', 'status', 'submitted_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['incurred_on' => 'date', 'amount' => 'integer', 'is_billable' => 'boolean', 'status' => EntryStatus::class, 'approved_at' => 'datetime'];
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
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'submitted_by');
    }
}
