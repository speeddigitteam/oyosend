<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Channels\Data\ChannelCredentials;

/**
 * A provider the operator configures once in the Super Admin panel (ADR 0013). The account holds the provider's
 * non-secret settings and its encrypted secrets; a workspace only selects the account.
 *
 * Secret fields are stored encrypted, never returned to a page (an empty value on edit keeps the stored one)
 * and never logged. The reserved secret keys are `secret` (the API credential the adapter receives as
 * {@see ChannelCredentials}), `webhook_secret` and `verify_token`.
 */
interface PlatformAccountProvider extends ChannelProvider
{
    /**
     * @return list<array{key: string, label: string, type: 'text'|'select'|'integer'|'secret', required: bool, options?: list<string>, maxLength?: int}>
     */
    public function accountFields(): array;

    /** Whether the account can be exercised with a test message to one address. */
    public function supportsTestSend(): bool;
}
