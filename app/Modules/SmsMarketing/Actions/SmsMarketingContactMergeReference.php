<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints group memberships and campaign recipients to the canonical contact during a core contact merge.
 * Where the canonical contact already has a row (same group, same campaign) the duplicate's row is dropped,
 * because both tables are unique per contact.
 */
final class SmsMarketingContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        if (! Schema::hasTable('sms_marketing_group_members') || ! Schema::hasTable('sms_marketing_recipients')) {
            return [];
        }

        $counts = [];

        $groups = DB::table('sms_marketing_group_members')
            ->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)->pluck('group_id')->all();

        if ($groups !== []) {
            DB::table('sms_marketing_group_members')
                ->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->whereIn('group_id', $groups)->delete();
        }

        $moved = DB::table('sms_marketing_group_members')
            ->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);

        if ($moved > 0) {
            $counts['sms_marketing_group_members'] = $moved;
        }

        $campaigns = DB::table('sms_marketing_recipients')
            ->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)->pluck('campaign_id')->all();

        if ($campaigns !== []) {
            DB::table('sms_marketing_recipients')
                ->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->whereIn('campaign_id', $campaigns)->delete();
        }

        $moved = DB::table('sms_marketing_recipients')
            ->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);

        if ($moved > 0) {
            $counts['sms_marketing_recipients'] = $moved;
        }

        return $counts;
    }
}
