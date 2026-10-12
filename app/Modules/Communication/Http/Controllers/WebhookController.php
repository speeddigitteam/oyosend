<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Actions\WebhookProcessor;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use App\Modules\Communication\Channels\ProviderRegistry;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Provider callbacks (delivery receipts, bounces, inbound messages). Stateless, on the workspace host.
 *
 * Order matters and is the whole security model: the workspace comes from the hostname; the provider adapter
 * must be one the workspace actually connected; the signature is verified against the secret the workspace's
 * `webhook_secret_ref` names, over the raw body, BEFORE anything is parsed; only then is an event applied,
 * once per provider event id. A request that fails any step changes nothing.
 */
final class WebhookController
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly CredentialResolver $credentials,
        private readonly WebhookProcessor $processor,
    ) {}

    public function receive(Request $request, string $provider): Response
    {
        $adapter = $this->providers->findByKey($provider);

        $connection = $adapter === null ? null : ChannelConnection::query()
            ->where('provider', $provider)->where('channel', $adapter->channel()->value)->where('is_enabled', true)->first();
        $secret = $connection === null ? null : $this->credentials->resolve($connection->webhook_secret_ref);

        // Unknown, unconnected or without a secret: nothing to verify against, and nothing is disclosed.
        if ($adapter === null || $connection === null || $secret === null || ! $adapter->supportsWebhooks()) {
            return response('Not found', 404)->header('Content-Type', 'text/plain');
        }

        $body = $request->getContent();

        if (strlen($body) > (int) config('communication.webhook_max_bytes', 262144)) {
            return response('Payload too large', 413)->header('Content-Type', 'text/plain');
        }

        try {
            $headers = [];

            foreach ($request->headers->all() as $name => $values) {
                $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
            }

            $events = $adapter->parseWebhook($body, $headers, $secret->secret());
        } catch (InvalidWebhookSignature) {
            return response('Invalid signature', 401)->header('Content-Type', 'text/plain');
        }

        $this->processor->process($adapter, $events);

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }
}
