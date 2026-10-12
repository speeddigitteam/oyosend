<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $employee_prefix
 * @property int $employee_padding
 * @property list<int> $weekly_off_days ISO weekdays, 1 = Monday ... 7 = Sunday
 * @property int $leave_year_start_month
 * @property int $work_day_minutes
 */
class HrmSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_settings';

    /** @var list<string> */
    protected $fillable = ['employee_prefix', 'employee_padding', 'weekly_off_days', 'leave_year_start_month', 'work_day_minutes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employee_padding' => 'integer',
            'weekly_off_days' => 'array',
            'leave_year_start_month' => 'integer',
            'work_day_minutes' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return 'HRM settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.settings.'.$verb;
    }
}
