<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Company property handed to an employee. A row with `returned_on` null is still held.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_id
 * @property string $name
 * @property string|null $asset_tag
 * @property string|null $category
 * @property Carbon $assigned_on
 * @property Carbon|null $returned_on
 * @property string|null $return_condition
 * @property string|null $notes
 * @property string|null $assigned_by
 * @property string|null $returned_by
 */
class EmployeeAsset extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_assets';

    /** @var list<string> */
    protected $fillable = [
        'employee_id', 'name', 'asset_tag', 'category', 'assigned_on', 'returned_on', 'return_condition',
        'notes', 'assigned_by', 'returned_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['assigned_on' => 'date', 'returned_on' => 'date'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isHeld(): bool
    {
        return $this->returned_on === null;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.asset.'.$verb;
    }
}
