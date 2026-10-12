<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Hrm\Enums\HolidayType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property Carbon $holiday_on
 * @property HolidayType $type
 */
class Holiday extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_holidays';

    /** @var list<string> */
    protected $fillable = ['name', 'holiday_on', 'type'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['holiday_on' => 'date', 'type' => HolidayType::class];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.holiday.'.$verb;
    }
}
