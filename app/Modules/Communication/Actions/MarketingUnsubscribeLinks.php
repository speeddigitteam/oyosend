<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Communication\Contracts\UnsubscribeLinks as UnsubscribeLinksContract;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use InvalidArgumentException;

/** The implementation of the core {@see UnsubscribeLinksContract}: hashed, expiring, tenant-host tokens. */
final class MarketingUnsubscribeLinks implements UnsubscribeLinksContract
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly UnsubscribeLinks $links,
    ) {}

    public function issue(string $contactId, MarketingChannel $channel, string $source): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,39}$/', $source) !== 1) {
            throw new InvalidArgumentException('A source is a module key of up to 40 lower-case characters.');
        }

        if (! $this->access->entitled()) {
            return null;
        }

        $contact = Contact::query()->find($contactId);

        return $contact === null ? null : $this->links->issueForContact($contact, MarketingChannelStatus::channel($channel));
    }
}
