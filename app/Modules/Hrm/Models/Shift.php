<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A working pattern. `starts_at` / `ends_at` are local wall-clock `HH:MM` in the
 * workspace timezone; an end at or before the start means the shift ends the next day.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $starts_at
 * @property string $ends_at
 * @property int $break_minutes
 * @property int $late_grace_minutes
 * @property int $early_grace_minutes
 * @property int $overtime_threshold_minutes
 * @property int|null $half_day_minutes
 * @property list<int>|null $weekly_off_days
 * @property bool $is_default
 * @property bool $is_active
 */
class Shift extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'hrm_shifts';

    /** @var list<string> */
    protected $fillable = [
        'name', 'starts_at', 'ends_at', 'break_minutes', 'late_grace_minutes', 'early_grace_minutes',
        'overtime_threshold_minutes', 'half_day_minutes', 'weekly_off_days', 'is_default', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'break_minutes' => 'integer',
            'late_grace_minutes' => 'integer',
            'early_grace_minutes' => 'integer',
            'overtime_threshold_minutes' => 'integer',
            'half_day_minutes' => 'integer',
            'weekly_off_days' => 'array',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** Minute of the local day (0..1439) the shift starts. */
    public function startMinute(): int
    {
        return self::minuteOf($this->starts_at);
    }

    public function crossesMidnight(): bool
    {
        return self::minuteOf($this->ends_at) <= $this->startMinute();
    }

    /** Length from start to end, break included. */
    public function spanMinutes(): int
    {
        $end = self::minuteOf($this->ends_at);

        return ($end > $this->startMinute() ? $end : $end + 1440) - $this->startMinute();
    }

    /** Minutes a person is expected to work: the span less the break. */
    public function scheduledMinutes(): int
    {
        return max(0, $this->spanMinutes() - $this->break_minutes);
    }

    public static function minuteOf(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $hour * 60 + $minute;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'hrm.shift.'.$verb;
    }
}
