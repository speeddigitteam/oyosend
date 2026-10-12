<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Workforce\Data\EmployeeSnapshot;
use App\Modules\Payroll\Enums\ItemStatus;
use App\Modules\Payroll\Enums\ItemType;
use App\Modules\Payroll\Enums\PeriodStatus;
use App\Modules\Payroll\Models\Item;
use App\Modules\Payroll\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-off pay items: allowances, bonuses (festival bonus included), deductions and reimbursements.
 * An item is applied by the run of its period and then becomes `included`; until then it can be
 * edited or cancelled.
 */
final class ItemManager
{
    public const int MAX_AMOUNT = 1_000_000_000_000;

    public const int MAX_BASIC_BPS = 100_000;

    public function __construct(private readonly Profiles $profiles) {}

    /** @param array<string, mixed> $attributes */
    public function create(EmployeeSnapshot $employee, Period $period, array $attributes, ?TenantMembership $actor = null): Item
    {
        $values = $this->values($attributes);
        $this->guardPeriod($period);
        $profile = $this->profiles->ensure($employee);

        return Item::query()->create([
            ...$values,
            'employee_id' => $employee->id,
            'owner_id' => $profile->owner_id,
            'department_id' => $profile->department_id,
            'branch_id' => $profile->branch_id,
            'period_id' => $period->id,
            'status' => ItemStatus::Pending,
            'created_by_id' => $actor?->id,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(Item $item, array $attributes): Item
    {
        return DB::transaction(function () use ($item, $attributes): Item {
            /** @var Item $locked */
            $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->guardPending($locked);
            $this->guardPeriod($locked->period);
            $locked->fill($this->values($attributes))->save();

            return $locked;
        });
    }

    public function cancel(Item $item): Item
    {
        return DB::transaction(function () use ($item): Item {
            /** @var Item $locked */
            $locked = Item::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $this->guardPending($locked);
            $locked->update(['status' => ItemStatus::Cancelled]);

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function values(array $attributes): array
    {
        $type = ItemType::from((string) $attributes['type']);
        $amount = isset($attributes['amount']) && $attributes['amount'] !== '' ? (int) $attributes['amount'] : null;
        $bps = isset($attributes['basic_bps']) && $attributes['basic_bps'] !== '' ? (int) $attributes['basic_bps'] : null;

        if (($amount === null) === ($bps === null)) {
            throw ValidationException::withMessages(['amount' => __('Enter either an amount or a percentage of basic, not both.')]);
        }

        if ($amount !== null && ($amount < 1 || $amount > self::MAX_AMOUNT)) {
            throw ValidationException::withMessages(['amount' => __('The amount is outside the supported range.')]);
        }

        if ($bps !== null && ($bps < 1 || $bps > self::MAX_BASIC_BPS)) {
            throw ValidationException::withMessages(['basic_bps' => __('The rate is outside the supported range.')]);
        }

        return [
            'type' => $type,
            'name' => (string) $attributes['name'],
            'amount' => $amount,
            'basic_bps' => $bps,
            'is_taxable' => in_array($type, [ItemType::Deduction, ItemType::Reimbursement], true)
                ? false
                : (bool) ($attributes['is_taxable'] ?? true),
            'note' => $attributes['note'] ?? null,
        ];
    }

    private function guardPending(Item $item): void
    {
        if ($item->status !== ItemStatus::Pending) {
            throw ValidationException::withMessages(['item' => __('Only a pending item can be changed.')]);
        }
    }

    private function guardPeriod(Period $period): void
    {
        if ($period->status === PeriodStatus::Closed) {
            throw ValidationException::withMessages(['period_id' => __('This pay period is closed.')]);
        }
    }
}
