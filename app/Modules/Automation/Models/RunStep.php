<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Automation\Enums\NodeType;
use App\Modules\Automation\Enums\StepStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One node execution inside a run (a token on the graph), with its redacted input, output and attempt log.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $node_id
 * @property string $node_type
 * @property StepStatus $status
 * @property int $attempt
 * @property int $sequence
 * @property int $iteration
 * @property string|null $scope
 * @property string|null $parent_step_id
 * @property Carbon|null $available_at
 * @property Carbon|null $resume_at
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $meta
 * @property list<array<string, mixed>>|null $logs
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
class RunStep extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_run_steps';

    /** @var list<string> */
    protected $fillable = [
        'run_id', 'node_id', 'node_type', 'status', 'attempt', 'sequence', 'iteration', 'scope', 'parent_step_id',
        'available_at', 'resume_at', 'input', 'output', 'meta', 'logs', 'error_code', 'error_message',
        'started_at', 'finished_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'input' => 'array',
            'output' => 'array',
            'meta' => 'array',
            'logs' => 'array',
            'available_at' => 'datetime',
            'resume_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    public function type(): NodeType
    {
        return NodeType::from($this->node_type);
    }
}
