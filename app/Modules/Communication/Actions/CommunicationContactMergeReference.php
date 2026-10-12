<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Modules\Communication\Enums\ConsentStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves messages, timeline entries, suppressions, tokens and consent of a duplicate contact to the canonical one.
 * When both have consent for the same channel and purpose the stricter answer survives: an opt-out wins.
 */
final class CommunicationContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        $counts = [];

        foreach (['communication_messages', 'communication_interactions', 'communication_suppressions', 'communication_unsubscribe_tokens'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)
                    ->where('tenant_id', $tenantId)
                    ->where('contact_id', $duplicateId)
                    ->update(['contact_id' => $canonicalId]);
            }
        }

        if (Schema::hasTable('communication_consents')) {
            $moved = 0;
            $duplicates = DB::table('communication_consents')->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->get();

            foreach ($duplicates as $duplicate) {
                $canonical = DB::table('communication_consents')
                    ->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)
                    ->where('channel', $duplicate->channel)->where('purpose', $duplicate->purpose)
                    ->first();

                if ($canonical === null) {
                    DB::table('communication_consents')->where('id', $duplicate->id)->update(['contact_id' => $canonicalId]);
                } else {
                    if ($duplicate->status === ConsentStatus::OptedOut->value && $canonical->status !== ConsentStatus::OptedOut->value) {
                        DB::table('communication_consents')->where('id', $canonical->id)->update([
                            'status' => ConsentStatus::OptedOut->value,
                            'method' => $duplicate->method,
                            'evidence' => $duplicate->evidence,
                            'recorded_at' => $duplicate->recorded_at,
                        ]);
                    }

                    DB::table('communication_consents')->where('id', $duplicate->id)->delete();
                }

                $moved++;
            }

            $counts['communication_consents'] = $moved;
        }

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
