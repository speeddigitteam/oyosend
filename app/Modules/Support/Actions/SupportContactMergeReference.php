<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Repoints tickets to the canonical contact during a core contact merge. */
final class SupportContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        if (! Schema::hasTable('support_tickets')) {
            return [];
        }

        $moved = DB::table('support_tickets')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $duplicateId)
            ->update(['contact_id' => $canonicalId]);

        return $moved > 0 ? ['support_tickets' => $moved] : [];
    }
}
