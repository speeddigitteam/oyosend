<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Core\Contacts\Contracts\ContactMergeReference;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves list memberships, deliveries and sequence enrolments of a duplicate contact to the canonical one.
 * Where both are members of the same list the stricter answer survives: a person who unsubscribed, bounced or
 * complained as either contact stays out.
 */
final class EmailMarketingContactMergeReference implements ContactMergeReference
{
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array
    {
        if (! Schema::hasTable('email_marketing_subscriptions')) {
            return [];
        }

        $counts = [];
        $moved = 0;

        $duplicates = DB::table('email_marketing_subscriptions')->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->get();

        foreach ($duplicates as $duplicate) {
            $canonical = DB::table('email_marketing_subscriptions')
                ->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)->where('list_id', $duplicate->list_id)->first();

            if ($canonical === null) {
                DB::table('email_marketing_subscriptions')->where('id', $duplicate->id)->update(['contact_id' => $canonicalId]);
            } else {
                $closed = [SubscriptionStatus::Unsubscribed->value, SubscriptionStatus::Bounced->value, SubscriptionStatus::Complained->value];

                if (in_array($duplicate->status, $closed, true) && ! in_array($canonical->status, $closed, true)) {
                    DB::table('email_marketing_subscriptions')->where('id', $canonical->id)->update([
                        'status' => $duplicate->status,
                        'unsubscribed_at' => $duplicate->unsubscribed_at,
                        'status_reason' => $duplicate->status_reason,
                    ]);
                }

                DB::table('email_marketing_deliveries')->where('tenant_id', $tenantId)->where('subscription_id', $duplicate->id)->update(['subscription_id' => $canonical->id]);
                DB::table('email_marketing_enrollments')->where('tenant_id', $tenantId)->where('subscription_id', $duplicate->id)->update(['subscription_id' => $canonical->id]);
                DB::table('email_marketing_subscriptions')->where('id', $duplicate->id)->delete();
            }

            $moved++;
        }

        $counts['email_marketing_subscriptions'] = $moved;
        $counts['email_marketing_deliveries'] = DB::table('email_marketing_deliveries')
            ->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->update(['contact_id' => $canonicalId]);

        $enrolments = 0;

        foreach (DB::table('email_marketing_enrollments')->where('tenant_id', $tenantId)->where('contact_id', $duplicateId)->get() as $enrolment) {
            $exists = DB::table('email_marketing_enrollments')
                ->where('tenant_id', $tenantId)->where('contact_id', $canonicalId)->where('sequence_id', $enrolment->sequence_id)->exists();

            if ($exists) {
                DB::table('email_marketing_enrollments')->where('id', $enrolment->id)->delete();
            } else {
                DB::table('email_marketing_enrollments')->where('id', $enrolment->id)->update(['contact_id' => $canonicalId]);
            }

            $enrolments++;
        }

        $counts['email_marketing_enrollments'] = $enrolments;

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }
}
