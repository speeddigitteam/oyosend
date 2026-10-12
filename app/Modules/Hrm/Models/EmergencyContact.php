<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $name
 * @property string $relationship
 * @property string $phone
 * @property string|null $email
 * @property string|null $address
 * @property bool $is_primary
 */
class EmergencyContact extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_emergency_contacts';

    /** @var list<string> */
    protected $fillable = ['employee_id', 'name', 'relationship', 'phone', 'email', 'address', 'is_primary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['phone', 'email', 'address'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.emergency_contact.'.$verb;
    }
}
