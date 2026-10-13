<?php

declare(strict_types=1);

namespace App\Modules\Communication\Http\Controllers;

use App\Modules\Communication\Actions\ChannelEventListenerFailed;
use App\Modules\Communication\Actions\ChannelNumbers;
use App\Modules\Communication\Actions\WebhookProcessor;
use App\Modules\Communication\Channels\Contracts\VerifiesWebhookSubscription;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Channels\Data\ProviderEvent;
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
        private readonly ChannelNumbers $numbers,
    ) {}

    public function receive(Request $request, string $provider): Response
    {
        $adapter = $this->providers->findByKey($provider);

        $connection = $adapter === null ? null : ChannelConnection::query()
            ->where('provider', $provider)->where('channel', $adapter->channel()->value)->where('is_enabled', true)->first();
        $secret = $connection === null ? null : $this->credentials->webhookSecret($connection->webhook_secret_ref, $connection->provider);

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

        // An event that names a provider account must name THIS connection's account: a provider app shared by
        // several workspaces can never feed one workspace another workspace's events.
        // With several numbers (ADR 0022) any number the workspace registered counts for `phone_number_id`.
        $events = array_values(array_filter($events, function (ProviderEvent $event) use ($connection): bool {
            foreach ($event->accounts as $key => $value) {
                if (! $this->numbers->acceptsAccount($connection, (string) $key, (string) $value)) {
                    return false;
                }
            }

            return true;
        }));

        try {
            $this->processor->process($adapter, $events);
        } catch (ChannelEventListenerFailed) {
            // Nothing of the event was kept: the provider redelivers it, and it is applied afresh.
            return response('Try again', 503)->header('Content-Type', 'text/plain')->header('Retry-After', '60');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * The subscription handshake some providers perform with a GET before they send events. It answers only
     * for a connected, enabled connection, with the workspace's own `verify_token`, in constant time.
     */
    public function verify(Request $request, string $provider): Response
    {
        $adapter = $this->providers->findByKey($provider);

        $connection = $adapter === null ? null : ChannelConnection::query()
            ->where('provider', $provider)->where('channel', $adapter->channel()->value)->where('is_enabled', true)->first();
        $token = $connection === null ? null : $this->credentials->attribute($connection->webhook_secret_ref, 'verify_token', $connection->provider);

        if (! $adapter instanceof VerifiesWebhookSubscription || $token === null) {
            return response('Not found', 404)->header('Content-Type', 'text/plain');
        }

        $query = [];

        foreach ($request->query() as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $query[$key] = $value;
            }
        }

        $answer = $adapter->answerHandshake($query, $token->secret());

        return $answer === null
            ? response('Forbidden', 403)->header('Content-Type', 'text/plain')
            : response($answer, 200)->header('Content-Type', 'text/plain');
    }
}
