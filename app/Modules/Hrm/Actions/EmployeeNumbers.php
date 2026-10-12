<?php

declare(strict_types=1);

namespace App\Modules\Hrm\Actions;

use App\Modules\Hrm\Models\NumberSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, race-safe employee numbers (`EMP-0001`).
 *
 * The counter row is locked (`FOR UPDATE`) and incremented inside the caller's
 * transaction, so two creators queue behind each other and a rollback hands the
 * number back: committed employees form an unbroken sequence.
 */
final class EmployeeNumbers
{
    public const string KIND = 'employee';

    public function __construct(private readonly HrmSettings $settings) {}

    /** The next formatted number. Must run inside the creating transaction. */
    public function next(): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('An employee number can only be allocated inside the creating transaction.');
        }

        $sequence = $this->lock();
        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);
        $settings = $this->settings->current();

        return $settings->employee_prefix.str_pad((string) $next, max(1, $settings->employee_padding), '0', STR_PAD_LEFT);
    }

    private function lock(): NumberSequence
    {
        $find = static fn (): ?NumberSequence => NumberSequence::query()->where('kind', self::KIND)->lockForUpdate()->first();
        $sequence = $find();

        if ($sequence !== null) {
            return $sequence;
        }

        try {
            NumberSequence::query()->create(['kind' => self::KIND, 'last_number' => 0]);
        } catch (UniqueConstraintViolationException) {
            // Another transaction created it first; wait for its lock below.
        }

        return $find() ?? throw new LogicException('The number sequence could not be locked.');
    }
}
