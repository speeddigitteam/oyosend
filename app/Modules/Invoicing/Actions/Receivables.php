<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Modules\Invoicing\Enums\InvoiceStatus;
use App\Modules\Invoicing\Models\CreditNote;
use App\Modules\Invoicing\Models\Invoice;
use App\Modules\Invoicing\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/**
 * Receivables, per currency. Amounts of different currencies are never added together.
 * Aggregates run in SQL over integer minor units and come back as exact integers.
 */
final class Receivables
{
    public const array BUCKETS = ['current', 'days_1_30', 'days_31_60', 'days_61_90', 'days_over_90'];

    public function __construct(private readonly InvoicingSettings $settings) {}

    /**
     * @return array{
     *   asOf: string,
     *   ageing: list<array{currency: string, invoices: int, outstanding: int, buckets: array<string, int>}>,
     *   byCustomer: list<array{contactId: string|null, companyId: string|null, name: string, currency: string, invoices: int, outstanding: int, oldestDueOn: string|null}>,
     *   collected: list<array{currency: string, payments: int, amount: int}>,
     *   collectedByMethod: list<array{currency: string, method: string, payments: int, amount: int}>,
     *   invoiced: list<array{currency: string, invoices: int, total: int, tax: int, credited: int}>
     * }
     */
    public function report(?string $from = null, ?string $to = null, ?string $currency = null): array
    {
        $today = $this->settings->today()->toDateString();
        $to ??= $today;
        $from ??= $this->settings->today()->startOfMonth()->toDateString();
        $currency = $currency === null || $currency === '' ? null : strtoupper($currency);

        return [
            'asOf' => $today,
            'ageing' => $this->ageing($today, $currency),
            'byCustomer' => $this->byCustomer($currency),
            'collected' => $this->collected($from, $to, $currency),
            'collectedByMethod' => $this->collectedByMethod($from, $to, $currency),
            'invoiced' => $this->invoiced($from, $to, $currency),
        ];
    }

    /** @return list<array{currency: string, invoices: int, outstanding: int, buckets: array<string, int>}> */
    private function ageing(string $today, ?string $currency): array
    {
        $bucket = static fn (string $name, string $condition): string => "COALESCE(SUM(CASE WHEN {$condition} THEN balance_amount ELSE 0 END), 0) AS {$name}";

        $rows = $this->open()
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('currency')
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(balance_amount), 0) AS outstanding')
            ->selectRaw($bucket('not_due', 'due_on >= ?'), [$today])
            ->selectRaw($bucket('days_1_30', 'due_on < ? AND due_on >= DATE_SUB(?, INTERVAL 30 DAY)'), [$today, $today])
            ->selectRaw($bucket('days_31_60', 'due_on < DATE_SUB(?, INTERVAL 30 DAY) AND due_on >= DATE_SUB(?, INTERVAL 60 DAY)'), [$today, $today])
            ->selectRaw($bucket('days_61_90', 'due_on < DATE_SUB(?, INTERVAL 60 DAY) AND due_on >= DATE_SUB(?, INTERVAL 90 DAY)'), [$today, $today])
            ->selectRaw($bucket('days_over_90', 'due_on < DATE_SUB(?, INTERVAL 90 DAY)'), [$today])
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get();

        return $rows->map(fn (object $row): array => [
            'currency' => (string) $row->currency,
            'invoices' => (int) $row->invoices,
            'outstanding' => (int) $row->outstanding,
            'buckets' => [
                'current' => (int) $row->not_due,
                'days_1_30' => (int) $row->days_1_30,
                'days_31_60' => (int) $row->days_31_60,
                'days_61_90' => (int) $row->days_61_90,
                'days_over_90' => (int) $row->days_over_90,
            ],
        ])->all();
    }

    /** @return list<array{contactId: string|null, companyId: string|null, name: string, currency: string, invoices: int, outstanding: int, oldestDueOn: string|null}> */
    private function byCustomer(?string $currency): array
    {
        $rows = $this->open()
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('company_id', 'contact_id', 'currency')
            ->selectRaw('MAX(customer_name) AS name')
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(balance_amount), 0) AS outstanding')
            ->selectRaw('MIN(due_on) AS oldest_due_on')
            ->groupBy('company_id', 'contact_id', 'currency')
            ->orderByDesc('outstanding')
            ->limit(100)
            ->toBase()
            ->get();

        return $rows->map(fn (object $row): array => [
            'contactId' => $row->contact_id,
            'companyId' => $row->company_id,
            'name' => (string) ($row->name ?? ''),
            'currency' => (string) $row->currency,
            'invoices' => (int) $row->invoices,
            'outstanding' => (int) $row->outstanding,
            'oldestDueOn' => $row->oldest_due_on === null ? null : substr((string) $row->oldest_due_on, 0, 10),
        ])->all();
    }

    /** @return list<array{currency: string, payments: int, amount: int}> */
    private function collected(string $from, string $to, ?string $currency): array
    {
        return Payment::query()
            ->whereNull('reversed_at')
            ->whereBetween('paid_on', [$from, $to])
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('currency')
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('currency')
            ->orderBy('currency')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'payments' => (int) $row->payments,
                'amount' => (int) $row->amount,
            ])->all();
    }

    /** @return list<array{currency: string, method: string, payments: int, amount: int}> */
    private function collectedByMethod(string $from, string $to, ?string $currency): array
    {
        return Payment::query()
            ->whereNull('reversed_at')
            ->whereBetween('paid_on', [$from, $to])
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('currency', 'method')
            ->selectRaw('COUNT(*) AS payments')
            ->selectRaw('COALESCE(SUM(amount), 0) AS amount')
            ->groupBy('currency', 'method')
            ->orderBy('currency')
            ->orderBy('method')
            ->toBase()
            ->get()
            ->map(fn (object $row): array => [
                'currency' => (string) $row->currency,
                'method' => (string) $row->method,
                'payments' => (int) $row->payments,
                'amount' => (int) $row->amount,
            ])->all();
    }

    /** @return list<array{currency: string, invoices: int, total: int, tax: int, credited: int}> */
    private function invoiced(string $from, string $to, ?string $currency): array
    {
        $invoices = Invoice::query()
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Void])
            ->whereBetween('issued_on', [$from, $to])
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('currency')
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->selectRaw('COALESCE(SUM(tax_amount), 0) AS tax')
            ->groupBy('currency')
            ->toBase()
            ->get();

        $credited = CreditNote::query()
            ->whereBetween('issued_on', [$from, $to])
            ->when($currency !== null, fn ($query) => $query->where('currency', $currency))
            ->select('currency')
            ->selectRaw('COALESCE(SUM(total_amount), 0) AS total')
            ->groupBy('currency')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->currency => (int) $row->total]);

        $result = [];

        foreach ($invoices as $row) {
            $result[(string) $row->currency] = [
                'currency' => (string) $row->currency,
                'invoices' => (int) $row->invoices,
                'total' => (int) $row->total,
                'tax' => (int) $row->tax,
                'credited' => (int) ($credited[$row->currency] ?? 0),
            ];
        }

        foreach ($credited as $code => $total) {
            $result[(string) $code] ??= ['currency' => (string) $code, 'invoices' => 0, 'total' => 0, 'tax' => 0, 'credited' => (int) $total];
        }

        ksort($result);

        return array_values($result);
    }

    /** Issued invoices that still owe money. */
    /** @return Builder<Invoice> */
    private function open(): Builder
    {
        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue])
            ->where('balance_amount', '>', 0);
    }
}
