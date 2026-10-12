<?php

declare(strict_types=1);

namespace App\Core\Communication\Contracts;

use App\Core\Communication\Data\MarketingChannel;

/**
 * Issues a workspace-scoped one-click unsubscribe URL for a contact.
 *
 * Use it when your own message body must embed the link (an HTML email template). Messages sent through
 * {@see ChannelGateway} already get the link and the `List-Unsubscribe` headers added by the platform.
 *
 * The token is 40 random characters that appear only in the returned URL; the database keeps its SHA-256 and
 * an expiry. Each call issues a new token. Opening the link changes nothing; the confirmation (POST) opts the
 * contact out and suppresses the address, and raises `communication.contact.unsubscribed`.
 *
 * Without the communication app (or when not entitled) it returns null: send nothing that needs a link.
 */
interface UnsubscribeLinks
{
    /**
     * @param  string  $source  The calling module's key (`email_marketing`). Validated, not stored.
     * @return string|null The absolute URL on the workspace host, or null when unavailable, the contact does
     *                     not exist or has no valid address on the channel.
     */
    public function issue(string $contactId, MarketingChannel $channel, string $source): ?string;
}
