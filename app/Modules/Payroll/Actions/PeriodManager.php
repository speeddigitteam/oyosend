<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Audit\AuditLogger;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\PeriodKind;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Enums\RunStatus;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Period;
use App\Modules\Payroll\Models\Run;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pay periods and their lifecycle: open, processing, approved, paid, closed.
 * The run manager moves a period through processing/approved/paid; people only create, edit
 * (while open and untouched), delete and close.
 */
final class PeriodManager
{
    public const int MAX_DAYS = 366;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{kind: string, month?: string|null, name?: string|null, starts_on?: string|null, ends_on?: string|null, pay_date?: string|null}  $attributes
     */
    public function create(array $attributes): Period
    {
        [$from, $to] = $this->range($attributes);

        return DB::transaction(function () use ($attributes, $from, $to): Period {
            $this->guardOverlap($from, $to, null);

            $period = Period::query()->create([
                'name' => $this->name($attributes, $from),
                'kind' => PeriodKind::from($attributes['kind']),
                'starts_on' => $from,
                'ends_on' => $to,
                'pay_date' => $attributes['pay_date'] ?? null,
                'status' => PeriodStatus::Open,
            ]);

            return $period;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(Period $period, array $attributes): Period
    {
        return DB::transaction(function () use ($period, $attributes): Period {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PeriodStatus::Open || $locked->runs()->exists()) {
                throw ValidationException::withMessages(['period' => __('Only an open period without runs can be changed.')]);
            }

            $attributes['kind'] ??= $locked->kind->value;
            [$from, $to] = $this->range($attributes);
            $this->guardOverlap($from, $to, $locked->id);

            $locked->fill([
                'name' => $this->name($attributes, $from),
                'kind' => PeriodKind::from((string) $attributes['kind']),
                'starts_on' => $from,
                'ends_on' => $to,
                'pay_date' => $attributes['pay_date'] ?? null,
            ])->save();

            return $locked;
        });
    }

    public function delete(Period $period): void
    {
        DB::transaction(function () use ($period): void {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PeriodStatus::Open || $locked->runs()->exists() || Item::query()->where('period_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['period' => __('Only an open period without runs or items can be deleted.')]);
            }

            $locked->delete();
        });
    }

    /** Close a fully paid period: nothing can be posted against it afterwards. */
    public function close(Period $period): Period
    {
        return DB::transaction(function () use ($period): Period {
            /** @var Period $locked */
            $locked = Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PeriodStatus::Paid) {
                throw ValidationException::withMessages(['period' => __('Only a fully paid period can be closed.')]);
            }

            if (Run::query()->where('period_id', $locked->id)->where('status', '!=', RunStatus::Approved)->exists()) {
                throw ValidationException::withMessages(['period' => __('Finish or discard the open runs of this period first.')]);
            }

            if (Item::query()->where('period_id', $locked->id)->where('status', ItemStatus::Pending)->exists()) {
                throw ValidationException::withMessages(['period' => __('Pending items remain for this period. Cancel them or pay them in an adjustment run.')]);
            }

            $locked->forceFill(['status' => PeriodStatus::Closed, 'closed_at' => now()])->save();
            $this->audit->record('payroll.period.closed', $locked);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: string}
     */
    private function range(array $attributes): array
    {
        if (($attributes['kind'] ?? '') === PeriodKind::Monthly->value) {
            $month = (string) ($attributes['month'] ?? '');

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
                throw ValidationException::withMessages(['month' => __('Choose a month.')]);
            }

            $start = CarbonImmutable::parse($month.'-01', 'UTC');

            return [$start->toDateString(), $start->endOfMonth()->toDateString()];
        }

        $from = (string) ($attributes['starts_on'] ?? '');
        $to = (string) ($attributes['ends_on'] ?? '');

        if ($from === '' || $to === '' || $to < $from) {
            throw ValidationException::withMessages(['ends_on' => __('The end date cannot be before the start date.')]);
        }

        if (CarbonImmutable::parse($from, 'UTC')->diffInDays(CarbonImmutable::parse($to, 'UTC')) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages(['ends_on' => __('A pay period can be at most :days days long.', ['days' => self::MAX_DAYS])]);
        }

        return [$from, $to];
    }

    /** @param array<string, mixed> $attributes */
    private function name(array $attributes, string $from): string
    {
        $name = trim((string) ($attributes['name'] ?? ''));

        if ($name !== '') {
            return $name;
        }

        return CarbonImmutable::parse($from, 'UTC')->locale(app()->getLocale())->isoFormat('MMMM YYYY');
    }

    private function guardOverlap(string $from, string $to, ?string $exceptId): void
    {
        $overlap = Period::query()
            ->where('starts_on', '<=', $to)
            ->where('ends_on', '>=', $from)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => __('This period overlaps an existing pay period.')]);
        }
    }
}
