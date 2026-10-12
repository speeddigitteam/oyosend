<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\LoanKind;
use App\Modules\Payroll\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A loan or salary advance repaid in monthly installments deducted from payroll runs.
 * Interest-free: the installments add up to exactly the principal.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property LoanKind $kind
 * @property string $title
 * @property int $principal_amount
 * @property int $installment_count
 * @property Carbon $first_due_on
 * @property string $currency
 * @property LoanStatus $status
 * @property string|null $reason
 * @property string|null $decision_note
 * @property string|null $requested_by_id
 * @property string|null $approved_by_id
 * @property Carbon|null $decided_at
 * @property-read Collection<int, LoanInstallment> $installments
 */
class Loan extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_loans';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'owner_id', 'department_id', 'branch_id', 'kind', 'title', 'principal_amount', 'installment_count',
        'first_due_on', 'currency', 'status', 'reason', 'decision_note', 'requested_by_id', 'approved_by_id', 'decided_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => LoanKind::class,
            'status' => LoanStatus::class,
            'principal_amount' => 'integer',
            'installment_count' => 'integer',
            'first_due_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /** @return HasMany<LoanInstallment, $this> */
    public function installments(): HasMany
    {
        return $this->hasMany(LoanInstallment::class)->orderBy('sequence');
    }

    public function auditLabel(): string
    {
        return $this->title;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.loan.'.$verb;
    }
}
