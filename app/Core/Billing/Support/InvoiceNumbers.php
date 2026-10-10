<?php

declare(strict_types=1);

namespace App\Core\Billing\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Hands out invoice numbers: `INV-2026-000042`. One counter for the whole
 * platform, taken under a row lock inside the caller's transaction, so a
 * number is used once and an invoice that fails to save leaves no gap.
 */
final class InvoiceNumbers
{
    private const string SEQUENCE = 'invoice';

    public function next(): string
    {
        DB::table('platform_sequences')->insertOrIgnore(['name' => self::SEQUENCE, 'value' => 0]);

        $current = (int) DB::table('platform_sequences')
            ->where('name', self::SEQUENCE)
            ->lockForUpdate()
            ->value('value');

        DB::table('platform_sequences')->where('name', self::SEQUENCE)->update(['value' => $current + 1]);

        return sprintf('%s-%s-%06d', (string) config('platform.billing.invoice_prefix', 'INV'), Carbon::now()->format('Y'), $current + 1);
    }
}
