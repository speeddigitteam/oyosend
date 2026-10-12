<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Modules\Support\Models\NumberSequence;
use App\Modules\Support\Models\Setting;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, race-safe ticket numbers per workspace.
 *
 * The counter row is locked (`FOR UPDATE`) and incremented inside the creating transaction, so concurrent
 * creators queue behind each other and a rollback hands the number back. Merged and split tickets keep
 * their numbers; numbers are never reused for another ticket and never skipped.
 */
final class TicketNumbers
{
    public const string KIND = 'ticket';

    /** The next formatted number. Must run inside the transaction that inserts the ticket. */
    public function next(Setting $settings): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A ticket number can only be allocated inside the creating transaction.');
        }

        $sequence = $this->lock();
        $next = $sequence->last_number + 1;
        $sequence->update(['last_number' => $next]);

        return $settings->ticket_prefix.str_pad((string) $next, max(1, $settings->number_padding), '0', STR_PAD_LEFT);
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

        return $find() ?? throw new LogicException('The ticket number sequence could not be locked.');
    }
}
