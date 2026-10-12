<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints this app's references to the canonical contact during a core contact merge. List memberships and
 * campaign recipients are unique per contact, so rows the canonical contact already has are dropped first.
 */
final class WhatsappContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        if (! Schema::hasTable('whatsapp_conversations')) {
            return [];
        }

        $moved = [];

        foreach ([['whatsapp_list_members', 'list_id'], ['whatsapp_campaign_recipients', 'campaign_id']] as [$table, $parent]) {
            $owned = DB::table($table)->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)->pluck($parent)->all();

            if ($owned !== []) {
                DB::table($table)->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->whereIn($parent, $owned)->delete();
            }

            $count = DB::table($table)->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);

            if ($count > 0) {
                $moved[$table] = $count;
            }
        }

        $conversations = DB::table('whatsapp_conversations')->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);

        if ($conversations > 0) {
            $moved['whatsapp_conversations'] = $conversations;
        }

        return $moved;
    }
}
