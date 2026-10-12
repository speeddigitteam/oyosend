<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use SensitiveParameter;

/**
 * An adapter whose provider confirms a callback URL with a handshake before it delivers events (the WhatsApp
 * Cloud API's `hub.mode=subscribe` request). The platform answers a GET on the provider's webhook route only for
 * a connected, enabled workspace connection whose `webhook_secret_ref` entry carries a `verify_token`.
 */
interface VerifiesWebhookSubscription
{
    /**
     * @param  array<string, string>  $query  The request's query parameters.
     * @return string|null The text to answer with when the handshake is valid; null refuses it.
     */
    public function answerHandshake(array $query, #[SensitiveParameter] string $verifyToken): ?string;
}
