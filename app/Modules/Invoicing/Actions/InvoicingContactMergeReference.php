<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints invoicing references to the canonical contact during a core contact merge.
 * Issued invoices keep their immutable name/address snapshot; only the link moves.
 */
final class InvoicingContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        $counts = [];

        foreach (['invoicing_invoices', 'invoicing_recurring_templates'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('contact_id', $duplicateId)
                    ->update(['contact_id' => $canonicalId]);
            }
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
