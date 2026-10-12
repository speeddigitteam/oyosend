<?php

declare(strict_types=1);

namespace App\Modules\Talent\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\TenantContext;
use App\Modules\Talent\Models\TalentSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Workspace Talent settings and the workspace clock (all dates are local dates in the workspace timezone). */
final class TalentSettings
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function current(): TalentSetting
    {
        $existing = TalentSetting::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return TalentSetting::query()->create([
                'default_probation_days' => 90,
                'probation_reminder_days' => 14,
                'certification_reminder_days' => 30,
                'candidate_retention_months' => 24,
            ])->refresh();
        } catch (UniqueConstraintViolationException) {
            return TalentSetting::query()->firstOrFail();
        }
    }

    /** @param array<string, mixed> $attributes */
    public function update(array $attributes): TalentSetting
    {
        return DB::transaction(function () use ($attributes): TalentSetting {
            $settings = $this->current();
            $settings->fill($attributes)->save();
            $this->audit->record('talent.settings.saved', $settings, [
                'default_probation_days' => $settings->default_probation_days,
                'candidate_retention_months' => $settings->candidate_retention_months,
            ]);

            return $settings;
        });
    }

    public function timezone(): string
    {
        return $this->context->require()->timezone;
    }

    public function currency(): string
    {
        return strtoupper((string) $this->context->require()->currency);
    }

    /** Today's date in the workspace timezone, at local midnight (kept in that zone). */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /** Whole days from today (workspace date) to a `Y-m-d` date; negative when it has passed. */
    public function daysUntil(string $date): int
    {
        return (int) CarbonImmutable::parse($this->today()->toDateString(), 'UTC')
            ->diffInDays(CarbonImmutable::parse($date, 'UTC'), false);
    }

    public function localDate(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone($this->timezone())->toDateString();
    }

    /** A local date and `H:i` time in the workspace timezone as a UTC instant. */
    public function toUtc(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$time, $this->timezone())
            ->setTimezone('UTC');
    }
}
