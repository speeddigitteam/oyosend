<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Automation\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A human task created by an approval node. The run waits until a permitted member decides or it expires.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $run_id
 * @property string $run_step_id
 * @property string $workflow_id
 * @property string $title
 * @property string|null $message
 * @property string $approver_type
 * @property string|null $approver_permission
 * @property string|null $approver_membership_id
 * @property bool $prevent_self_approval
 * @property string|null $requested_by_membership_id
 * @property ApprovalStatus $status
 * @property string $on_timeout
 * @property string|null $decided_by_membership_id
 * @property string|null $decision_comment
 * @property Carbon|null $decided_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 */
class Approval extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_approvals';

    /** @var list<string> */
    protected $fillable = [
        'run_id', 'run_step_id', 'workflow_id', 'title', 'message', 'approver_type', 'approver_permission',
        'approver_membership_id', 'prevent_self_approval', 'requested_by_membership_id', 'status', 'on_timeout',
        'decided_by_membership_id', 'decision_comment', 'decided_at', 'expires_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'prevent_self_approval' => 'boolean',
            'decided_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Run, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'run_id');
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }
}
