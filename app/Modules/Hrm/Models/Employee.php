<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\EmployeeStatus;
use App\Modules\Hrm\Enums\EmploymentType;
use App\Modules\Hrm\Enums\Gender;
use App\Modules\Hrm\Enums\TerminationType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person employed by the workspace. `owner_id` is the linked
 * {@see TenantMembership} (optional): it is what the `own` permission scope and
 * self-service hang on. Department and branch are the employee's HR placement and
 * are what `department` / `branch` scopes compare against.
 *
 * It does not use `HasRecordOwner`: HR creates employees for other people, and the
 * creator must not become the owner.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_number
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $first_name
 * @property string|null $last_name
 * @property string|null $work_email
 * @property string|null $personal_email
 * @property string|null $phone
 * @property Carbon|null $date_of_birth
 * @property Gender|null $gender
 * @property string|null $national_id
 * @property string|null $address
 * @property string|null $city
 * @property string|null $country
 * @property string|null $designation_id
 * @property string|null $manager_id
 * @property EmploymentType $employment_type
 * @property EmployeeStatus $status
 * @property Carbon $joined_on
 * @property Carbon|null $probation_ends_on
 * @property Carbon|null $confirmed_on
 * @property Carbon|null $status_changed_on
 * @property string|null $status_reason
 * @property Carbon|null $terminated_on
 * @property Carbon|null $last_working_on
 * @property TerminationType|null $termination_type
 * @property string|null $termination_reason
 * @property int|null $cost_rate_amount
 * @property string|null $cost_currency
 * @property string|null $notes
 * @property-read TenantMembership|null $owner
 * @property-read Designation|null $designation
 * @property-read Department|null $department
 * @property-read Branch|null $branch
 * @property-read Employee|null $manager
 */
class Employee extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_employees';

    /** @var list<string> */
    protected $fillable = [
        'employee_number', 'owner_id', 'department_id', 'branch_id', 'first_name', 'last_name', 'work_email',
        'personal_email', 'phone', 'date_of_birth', 'gender', 'national_id', 'address', 'city', 'country',
        'designation_id', 'manager_id', 'employment_type', 'status', 'joined_on', 'probation_ends_on',
        'confirmed_on', 'status_changed_on', 'status_reason', 'terminated_on', 'last_working_on',
        'termination_type', 'termination_reason', 'cost_rate_amount', 'cost_currency', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'gender' => Gender::class,
            'national_id' => 'encrypted',
            'employment_type' => EmploymentType::class,
            'status' => EmployeeStatus::class,
            'joined_on' => 'date',
            'probation_ends_on' => 'date',
            'confirmed_on' => 'date',
            'status_changed_on' => 'date',
            'terminated_on' => 'date',
            'last_working_on' => 'date',
            'termination_type' => TerminationType::class,
            'cost_rate_amount' => 'integer',
        ];
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.($this->last_name ?? ''));
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
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

    /** @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    /** @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    /** @return HasMany<EmploymentHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(EmploymentHistory::class);
    }

    /** @return HasMany<EmergencyContact, $this> */
    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<AttendanceDay, $this> */
    public function attendanceDays(): HasMany
    {
        return $this->hasMany(AttendanceDay::class);
    }

    /** @return HasMany<EmployeeAsset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class);
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return [
            'national_id', 'date_of_birth', 'personal_email', 'phone', 'address', 'city', 'cost_rate_amount', 'cost_currency',
            'notes', 'status_reason', 'termination_reason',
        ];
    }

    public function auditLabel(): string
    {
        return $this->employee_number.' '.$this->fullName();
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.employee.'.$verb;
    }
}
