<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Models\TaxRate;
use Illuminate\Validation\ValidationException;

/**
 * Turns submitted line input into exact line snapshots and document totals.
 * Totals are always calculated here; a client never supplies them.
 */
final class InvoiceLines
{
    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, totals: array{subtotal_amount: int, discount_amount: int, tax_amount: int, total_amount: int}}
     */
    public function prepare(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => __('An invoice needs at least one line.')]);
        }

        if (count($lines) > InvoiceMath::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => __('An invoice can have at most :count lines.', ['count' => InvoiceMath::MAX_LINES])]);
        }

        $rates = [];
        $snapshots = [];

        foreach ($lines as $index => $input) {
            $name = trim((string) ($input['name'] ?? ''));

            if ($name === '') {
                throw ValidationException::withMessages(["lines.{$index}.name" => __('A line needs a name.')]);
            }

            [$taxBps, $taxName, $taxRateId] = $this->tax($input, $index, $rates);
            $calculated = InvoiceMath::line(
                (int) ($input['unit_price_amount'] ?? 0),
                (int) ($input['quantity_milli'] ?? 0),
                isset($input['discount_amount']) ? (int) $input['discount_amount'] : null,
                isset($input['discount_bps']) ? (int) $input['discount_bps'] : null,
                $taxBps,
            );

            $snapshots[] = [
                'position' => $index + 1,
                'name' => $name,
                'description' => isset($input['description']) && trim((string) $input['description']) !== ''
                    ? (string) $input['description'] : null,
                'unit' => trim((string) ($input['unit'] ?? '')) !== '' ? trim((string) $input['unit']) : 'unit',
                'quantity_milli' => (int) $input['quantity_milli'],
                'unit_price_amount' => (int) $input['unit_price_amount'],
                'gross_amount' => $calculated['gross_amount'],
                'discount_bps' => isset($input['discount_bps']) && (int) $input['discount_bps'] > 0 ? (int) $input['discount_bps'] : null,
                'discount_amount' => $calculated['discount_amount'],
                'net_amount' => $calculated['net_amount'],
                'tax_rate_id' => $taxRateId,
                'tax_name' => $taxName,
                'tax_bps' => $taxBps,
                'tax_amount' => $calculated['tax_amount'],
                'line_total_amount' => $calculated['line_total_amount'],
            ];
        }

        return [
            'lines' => $snapshots,
            'totals' => [
                'subtotal_amount' => InvoiceMath::sum(array_column($snapshots, 'gross_amount')),
                'discount_amount' => InvoiceMath::sum(array_column($snapshots, 'discount_amount')),
                'tax_amount' => InvoiceMath::sum(array_column($snapshots, 'tax_amount')),
                'total_amount' => InvoiceMath::sum(array_column($snapshots, 'line_total_amount')),
            ],
        ];
    }

    /**
     * What a template stores: the line with its tax resolved, so a later change to a tax
     * rate cannot silently change a recurring invoice.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    public function normalize(array $lines): array
    {
        return array_map(static fn (array $line): array => [
            'name' => $line['name'],
            'description' => $line['description'],
            'unit' => $line['unit'],
            'quantity_milli' => $line['quantity_milli'],
            'unit_price_amount' => $line['unit_price_amount'],
            'discount_bps' => $line['discount_bps'],
            'discount_amount' => $line['discount_bps'] === null ? $line['discount_amount'] : 0,
            'tax_name' => $line['tax_name'],
            'tax_bps' => $line['tax_bps'],
        ], $this->prepare($lines)['lines']);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, TaxRate>  $rates
     * @return array{0: int, 1: string|null, 2: string|null}
     */
    private function tax(array $input, int $index, array &$rates): array
    {
        $rateId = $input['tax_rate_id'] ?? null;

        if ($rateId === null || $rateId === '') {
            $bps = (int) ($input['tax_bps'] ?? 0);
            $name = isset($input['tax_name']) && trim((string) $input['tax_name']) !== '' ? mb_substr(trim((string) $input['tax_name']), 0, 80) : null;

            return [$bps, $bps === 0 ? null : $name, null];
        }

        $rate = $rates[(string) $rateId] ??= TaxRate::query()->where('is_active', true)->find((string) $rateId)
            ?? throw ValidationException::withMessages(["lines.{$index}.tax_rate_id" => __('The selected tax rate is not available.')]);

        return [$rate->rate_bps, $rate->name, $rate->id];
    }
}
