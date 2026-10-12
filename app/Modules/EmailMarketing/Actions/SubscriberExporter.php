<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Actions;

use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Support\CsvSafe;
use Generator;

/** The members of a list as a CSV that a spreadsheet cannot execute ({@see CsvSafe}). */
final class SubscriberExporter
{
    public const array COLUMNS = [
        'email', 'first_name', 'last_name', 'status', 'consent_method', 'consent_evidence', 'consented_at',
        'subscribed_at', 'unsubscribed_at', 'source', 'properties',
    ];

    public function csv(MailingList $list): string
    {
        return CsvSafe::render(self::COLUMNS, $this->rows($list));
    }

    /** @return Generator<int, list<string|null>> */
    private function rows(MailingList $list): Generator
    {
        $max = (int) config('email_marketing.export.max_rows', 50000);
        $count = 0;

        $members = Subscription::query()->with('contact')->whereHas('contact')->where('list_id', $list->id)->orderBy('id')->lazyById(500);

        foreach ($members as $member) {
            if (++$count > $max) {
                return;
            }

            yield [
                $member->contact->email,
                $member->contact->first_name,
                $member->contact->last_name,
                $member->status->value,
                $member->consent_method,
                $member->consent_evidence,
                $member->consented_at?->toIso8601String(),
                $member->subscribed_at?->toIso8601String(),
                $member->unsubscribed_at?->toIso8601String(),
                $member->source,
                $member->properties === null ? null : (string) json_encode($member->properties, JSON_UNESCAPED_UNICODE),
            ];
        }
    }
}
