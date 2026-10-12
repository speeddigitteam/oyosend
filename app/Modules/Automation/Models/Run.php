<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Automation\Enums\RunMode;
use App\Modules\Automation\Enums\RunSource;
use App\Modules\Automation\Enums\RunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a workflow version. Everything needed to continue it after a restart is in this row
 * and its steps: nothing lives in a queue payload or in memory.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_id
 * @property string $version_id
 * @property int $version_number
 * @property RunMode $mode
 * @property RunSource $source
 * @property RunStatus $status
 * @property string|null $trigger_key
 * @property string $idempotency_key
 * @property int $depth
 * @property string|null $parent_run_id
 * @property string|null $started_by_membership_id
 * @property array<string, mixed>|null $graph
 * @property array<string, mixed>|null $context
 * @property int $steps_executed
 * @property int $retry_count
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $locked_until
 * @property Carbon|null $resume_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property bool $cancel_requested
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Run extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_runs';

    /** @var list<string> */
    protected $fillable = [
        'workflow_id', 'version_id', 'version_number', 'mode', 'source', 'status', 'trigger_key', 'idempotency_key',
        'depth', 'parent_run_id', 'started_by_membership_id', 'graph', 'context', 'steps_executed', 'retry_count',
        'error_code', 'error_message', 'locked_until', 'resume_at', 'started_at', 'finished_at', 'cancel_requested',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'mode' => RunMode::class,
            'source' => RunSource::class,
            'status' => RunStatus::class,
            'graph' => 'array',
            'context' => 'array',
            'locked_until' => 'datetime',
            'resume_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'version_id');
    }

    /** @return HasMany<RunStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class, 'run_id');
    }

    /** @return HasMany<Approval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class, 'run_id');
    }

    public function isDryRun(): bool
    {
        return $this->mode === RunMode::DryRun;
    }
}
