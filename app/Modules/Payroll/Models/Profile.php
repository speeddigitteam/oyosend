<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Payroll's own record per employee: placement copied from HRM (so scoped permissions work through
 * `RecordAccess`) and the payment account. The salary itself lives in the immutable assignments.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property PaymentMethod|null $payment_method
 * @property string|null $account_holder
 * @property string|null $bank_name
 * @property string|null $bank_branch
 * @property string|null $routing_number
 * @property string|null $account_number
 * @property-read Collection<int, SalaryAssignment> $assignments
 */
class Profile extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_profiles';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'owner_id', 'department_id', 'branch_id', 'payment_method', 'account_holder', 'bank_name',
        'bank_branch', 'routing_number', 'account_number',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return ['payment_method' => PaymentMethod::class, 'account_number' => 'encrypted'];
    }

    /** @return HasMany<SalaryAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(SalaryAssignment::class, 'profile_id')->orderByDesc('effective_from');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** The account number with all but the last four characters hidden. */
    public function maskedAccountNumber(): ?string
    {
        $number = $this->account_number;

        if ($number === null || $number === '') {
            return null;
        }

        return str_repeat('*', max(0, strlen($number) - 4)).substr($number, -4);
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['account_number', 'account_holder', 'bank_name', 'bank_branch', 'routing_number'];
    }

    public function auditLabel(): string
    {
        return 'Payroll profile';
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.profile.'.$verb;
    }
}
