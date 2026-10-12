<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Models\NumberSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, race-safe document numbers for approved runs and payslips.
 *
 * The counter row is locked (`FOR UPDATE`) and incremented inside the approving transaction, so a
 * number only ever goes to a document that was committed. Drafts have no number.
 */
final class NumberAllocator
{
    public const string PAYSLIP = 'payslip';

    public const string RUN = 'run';

    public function __construct(private readonly PayrollSettings $settings) {}

    /** The next formatted number of a sequence. Must run inside a transaction. */
    public function next(string $kind): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A payroll number can only be allocated inside the approving transaction.');
        }

        $sequence = $this->lock($kind);
        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);

        $settings = $this->settings->current();
        $prefix = $kind === self::RUN ? $settings->run_prefix : $settings->payslip_prefix;

        return $prefix.str_pad((string) $next, max(1, $settings->number_padding), '0', STR_PAD_LEFT);
    }

    private function lock(string $kind): NumberSequence
    {
        $find = static fn (): ?NumberSequence => NumberSequence::query()->where('kind', $kind)->lockForUpdate()->first();
        $sequence = $find();

        if ($sequence !== null) {
            return $sequence;
        }

        try {
            NumberSequence::query()->create(['kind' => $kind, 'last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another transaction created it first; wait for its lock below.
        }

        return $find() ?? throw new LogicException('The number sequence could not be locked.');
    }
}
