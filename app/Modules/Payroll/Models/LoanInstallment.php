<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\InstallmentStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $loan_id
 * @property string $employee_id
 * @property int $sequence
 * @property Carbon $due_on
 * @property int $amount
 * @property InstallmentStatus $status
 * @property string|null $payslip_id
 * @property-read Loan $loan
 */
class LoanInstallment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_loan_installments';

    /** @var list<string> */
    protected $fillable = ['loan_id', 'employee_id', 'sequence', 'due_on', 'amount', 'status', 'payslip_id'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'due_on' => 'date', 'amount' => 'integer', 'status' => InstallmentStatus::class];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }
}
