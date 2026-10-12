<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Hrm\Models\HrmSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace HRM settings and the workspace calendar. */
final class HrmSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /** The row for the active workspace; created with defaults on first use. */
    public function current(): HrmSetting
    {
        $existing = HrmSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return HrmSetting::query()->create(['weekly_off_days' => [5, 6]])->refresh();
        } catch (UniqueConstraintViolationException) {
            return HrmSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): HrmSetting
    {
        return DB::transaction(function () use ($attributes): HrmSetting {
            $settings = $this->current();
            $settings->fill($attributes)->save();
            $this->audit->record('hrm.settings.saved', $settings, ['employee_prefix' => $settings->employee_prefix]);

            return $settings;
        });
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    /** Today's date in the workspace timezone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /** The local date of an instant in the workspace timezone. */
    public function localDate(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone())->toDateString();
    }

    /** The leave year (the calendar year it started in) a local date belongs to. */
    public function leaveYear(string $date): int
    {
        $start = $this->current()->leave_year_start_month;
        $parsed = CarbonImmutable::parse($date, 'UTC');

        return $parsed->month >= $start ? $parsed->year : $parsed->year - 1;
    }

    /** First day of a leave year. */
    public function leaveYearStart(int $year): CarbonImmutable
    {
        return CarbonImmutable::create($year, $this->current()->leave_year_start_month, 1, 0, 0, 0, 'UTC');
    }
}
