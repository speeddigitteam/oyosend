<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\ApprovalStep;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One decision on one approval step of a leave request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $leave_request_id
 * @property ApprovalStep $step
 * @property string $decision `approved` or `rejected`
 * @property string|null $decided_by
 * @property string|null $note
 * @property Carbon $decided_at
 */
class LeaveApproval extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_leave_approvals';

    /** @var list<string> */
    protected $fillable = ['leave_request_id', 'step', 'decision', 'decided_by', 'note', 'decided_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['step' => ApprovalStep::class, 'decided_at' => 'datetime'];
    }
}
