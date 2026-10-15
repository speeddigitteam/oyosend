<?php

declare(strict_types=1);

namespace App\Modules\Support\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Repoints tickets and website chats to the canonical contact during a core contact merge. */
final class SupportContactMergeReference implements ContactMergeReference
{
    private const array TABLES = ['support_tickets', 'support_chat_conversations'];

    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        $moved = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'contact_id')) {
                continue;
            }

            $count = DB::table($table)
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $duplicateId)
                ->update(['contact_id' => $canonicalId]);

            if ($count > 0) {
                $moved[$table] = $count;
            }
        }

        return $moved;
    }
}
