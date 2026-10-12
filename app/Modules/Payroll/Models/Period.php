<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Payroll\Enums\PeriodKind;
use App\Modules\Payroll\Enums\PeriodStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property PeriodKind $kind
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property Carbon|null $pay_date
 * @property PeriodStatus $status
 * @property Carbon|null $closed_at
 * @property-read Collection<int, Run> $runs
 */
class Period extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'payroll_periods';

    /** @var list<string> */
    protected $fillable = ['name', 'kind', 'starts_on', 'ends_on', 'pay_date', 'status', 'closed_at'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'kind' => PeriodKind::class,
            'status' => PeriodStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'pay_date' => 'date',
            'closed_at' => 'datetime',
        ];
    }

    /** @return HasMany<Run, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class)->orderBy('created_at')->orderBy('id');
    }

    /** Whole calendar days in the period, both ends included. */
    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'payroll.period.'.$verb;
    }
}
