<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Models\SalaryStructure;
use App\Modules\Payroll\Models\StructureComponent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Salary structures and their recurring components. */
final class StructureManager
{
    /** Largest fixed amount of one component, in minor units. */
    public const int MAX_AMOUNT = 1_000_000_000_000;

    /** Largest percentage of basic: 1000 %. */
    public const int MAX_RATE_BPS = 100_000;

    /** @param array<string, mixed> $attributes */
    public function saveStructure(?SalaryStructure $structure, array $attributes): SalaryStructure
    {
        return DB::transaction(function () use ($structure, $attributes): SalaryStructure {
            $structure ??= new SalaryStructure;
            $name = (string) $attributes['name'];
            $clash = SalaryStructure::query()->where('name', $name)->when($structure->exists, fn ($query) => $query->whereKeyNot($structure->id))->exists();

            if ($clash) {
                throw ValidationException::withMessages(['name' => __('A salary structure with this name already exists.')]);
            }

            $structure->fill([
                'name' => $name,
                'code' => $attributes['code'] ?? null,
                'description' => $attributes['description'] ?? null,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $structure;
        });
    }

    /** @param array<string, mixed> $attributes */
    public function saveComponent(SalaryStructure $structure, ?StructureComponent $component, array $attributes): StructureComponent
    {
        $fixed = $attributes['calc'] === 'fixed';
        $amount = $fixed ? (int) ($attributes['amount'] ?? 0) : null;
        $rate = $fixed ? null : (int) ($attributes['rate_bps'] ?? 0);

        if ($amount !== null && ($amount < 0 || $amount > self::MAX_AMOUNT)) {
            throw ValidationException::withMessages(['amount' => __('The amount is outside the supported range.')]);
        }

        if ($rate !== null && ($rate < 0 || $rate > self::MAX_RATE_BPS)) {
            throw ValidationException::withMessages(['rate_bps' => __('The rate is outside the supported range.')]);
        }

        $from = $attributes['effective_from'] ?? null;
        $to = $attributes['effective_to'] ?? null;

        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => __('The end date cannot be before the start date.')]);
        }

        return DB::transaction(function () use ($structure, $component, $attributes, $amount, $rate, $from, $to): StructureComponent {
            $code = strtoupper((string) $attributes['code']);
            $clash = StructureComponent::query()->where('structure_id', $structure->id)->where('code', $code)
                ->when($component !== null, fn ($query) => $query->whereKeyNot($component?->id))->exists();

            if ($clash) {
                throw ValidationException::withMessages(['code' => __('This code is already used in the structure.')]);
            }

            $component ??= new StructureComponent(['structure_id' => $structure->id]);
            $component->fill([
                'name' => $attributes['name'],
                'code' => $code,
                'kind' => $attributes['kind'],
                'calc' => $attributes['calc'],
                'amount' => $amount,
                'rate_bps' => $rate,
                'is_taxable' => (bool) ($attributes['is_taxable'] ?? true),
                'sort_order' => (int) ($attributes['sort_order'] ?? 0),
                'effective_from' => $from,
                'effective_to' => $to,
                'is_active' => (bool) ($attributes['is_active'] ?? true),
            ])->save();

            return $component;
        });
    }

    public function deleteComponent(StructureComponent $component): void
    {
        $component->delete();
    }
}
