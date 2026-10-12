<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Invoicing\Models\InvoicingSetting;
use App\Modules\Invoicing\Models\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Gap-free, race-safe document numbers.
 *
 * The counter row is locked (`FOR UPDATE`) and incremented inside the caller's
 * transaction, so two issuers queue up behind each other and a rollback hands the
 * number back. A number is therefore only ever allocated to a document that was
 * committed, and the sequence has no holes. Drafts have no number.
 */
final class NumberAllocator
{
    public const string INVOICE = 'invoice';

    public const string CREDIT_NOTE = 'credit_note';

    public function __construct(private readonly TenantContext $context) {}

    /** The next formatted number of a sequence. Must run inside a transaction. */
    public function next(string $kind, CarbonImmutable $date, InvoicingSetting $settings): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A document number can only be allocated inside the issuing transaction.');
        }

        $period = $settings->fiscal_year_numbering ? $this->fiscalYear($date, $settings->fiscal_year_start_month) : 0;
        $sequence = $this->lock($kind, $period);
        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);

        $prefix = $kind === self::CREDIT_NOTE ? $settings->credit_note_prefix : $settings->invoice_prefix;
        $label = '';

        if ($period > 0) {
            $label = $settings->fiscal_year_start_month === 1
                ? (string) $period
                : $period.'-'.substr((string) ($period + 1), -2);
            $label .= '-';
        }

        return $prefix.$label.str_pad((string) $next, max(1, $settings->number_padding), '0', STR_PAD_LEFT);
    }

    /** The year the fiscal year containing the date started in. */
    public function fiscalYear(CarbonImmutable $date, int $startMonth): int
    {
        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    private function lock(string $kind, int $period): NumberSequence
    {
        // Make sure the row exists first (a no-op when it does), so the locking read below always
        // finds it and takes a plain row lock instead of a gap lock that two first issuers could deadlock on.
        NumberSequence::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'tenant_id' => $this->context->requireId(),
            'kind' => $kind,
            'period' => $period,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return NumberSequence::query()
            ->where('kind', $kind)->where('period', $period)->lockForUpdate()->first()
            ?? throw new LogicException('The number sequence could not be locked.');
    }
}
