<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\OutboundPayload;
use App\Modules\Communication\Channels\Data\ProviderEvent;
use App\Modules\Communication\Channels\Data\SendResult;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConfigurationState;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * An adapter between the outbox and one external messaging service.
 *
 * Rules every adapter keeps (docs/COMMUNICATION.md):
 *  - it receives the secret only as {@see ChannelCredentials} for the duration of `send()` and never logs it,
 *    puts it in an exception message or returns it;
 *  - it never pretends: without credentials `state()` is not-configured and `send()` rejects;
 *  - `send()` does not throw for provider failures, it answers with a {@see SendResult};
 *  - `parseWebhook()` verifies the signature BEFORE it reads anything from the body.
 *
 * Extend one of the channel interfaces ({@see EmailChannel}, {@see SmsChannel}, {@see WhatsAppChannel}), not this.
 */
interface ChannelProvider
{
    /** Stable identifier stored on connections and messages: `mail`, `none`, `acme_sms`. */
    public function key(): string;

    /** English label (a translation key). */
    public function label(): string;

    public function channel(): Channel;

    /** True for the in-memory test doubles bound only in `testing` / `local`. */
    public function isTestDouble(): bool;

    /**
     * The non-secret settings the connection form shows for this provider.
     *
     * @return list<array{key: string, label: string, type: 'text'|'select'|'integer', required: bool, options?: list<string>, maxLength?: int}>
     */
    public function fields(): array;

    /** Whether this provider can receive verified webhooks. */
    public function supportsWebhooks(): bool;

    /**
     * `NotConfigured` unless this adapter can really send: an adapter that needs a secret must answer
     * `NotConfigured` while the connection has no `credential_ref` (the platform additionally checks that a
     * reference resolves for the workspace).
     */
    public function state(ChannelConnection $connection): ConfigurationState;

    public function send(OutboundPayload $payload, ChannelConnection $connection, ?ChannelCredentials $credentials): SendResult;

    /**
     * Verify and parse a webhook delivery.
     *
     * @param  array<string, string>  $headers  Lower-cased header names.
     * @return list<ProviderEvent>
     *
     * @throws InvalidWebhookSignature When the signature is missing, malformed or wrong.
     */
    public function parseWebhook(string $body, array $headers, string $secret): array;
}
