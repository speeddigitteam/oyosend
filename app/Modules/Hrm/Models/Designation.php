<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A job title. Departments and branches are the core organisation models;
 * designations belong to HRM.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 * @property bool $is_active
 */
class Designation extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_designations';

    /** @var list<string> */
    protected $fillable = ['name', 'code', 'description', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.designation.'.$verb;
    }
}
