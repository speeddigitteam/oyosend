<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine\Outbound;

use App\Modules\Automation\Models\WebhookEndpoint;
use App\Modules\Automation\Support\Limits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends a webhook node's payload to a registered endpoint.
 *
 * The payload is JSON, signed with the endpoint's secret as `sha256=HMAC(secret, "{timestamp}.{body}")`
 * (headers `X-Oyosend-Timestamp`, `X-Oyosend-Signature`), carries the step's idempotency key so a receiver
 * can de-duplicate a retry, and is sent without following redirects, with a short timeout, to addresses
 * the {@see UrlGuard} approved. The secret never appears in logs or in a thrown message.
 */
final class OutboundWebhook
{
    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, retryable: bool, message: string}
     */
    public function send(WebhookEndpoint $endpoint, array $payload, string $idempotencyKey): array
    {
        $inspection = $this->guard->inspect($endpoint->url);

        if (is_string($inspection)) {
            return ['status' => 0, 'retryable' => false, 'message' => $inspection];
        }

        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) Carbon::now()->timestamp;
        $headers = [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Oyosend-Webhooks/1',
            'X-Oyosend-Timestamp' => $timestamp,
            'X-Oyosend-Idempotency-Key' => $idempotencyKey,
        ];

        if ($endpoint->secret !== null && $endpoint->secret !== '') {
            $headers['X-Oyosend-Signature'] = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $endpoint->secret);
        }

        // Only the status is used: never download more than a small cap of the answer.
        $cap = new ResponseCap;
        $options = ['allow_redirects' => false, 'on_headers' => $cap->onHeaders(...), 'progress' => $cap->onProgress(...)];

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => array_map(
                static fn (string $address): string => $inspection['host'].':443:'.$address,
                $inspection['addresses'],
            )];
        }

        try {
            $response = Http::withHeaders($headers)
                ->withOptions($options)
                ->timeout(Limits::int('outbound_timeout_seconds'))
                ->withBody($body, 'application/json')
                ->post($endpoint->url);
        } catch (Throwable) {
            if ($cap->exceeded && $cap->status > 0) {
                // The answer was cut off at the cap; its status line still tells the outcome.
                return $this->outcome($cap->status);
            }

            return ['status' => 0, 'retryable' => true, 'message' => 'The endpoint could not be reached.'];
        }

        return $this->outcome($response->status());
    }

    /**
     * @return array{status: int, retryable: bool, message: string}
     */
    private function outcome(int $status): array
    {
        if ($status >= 200 && $status < 300) {
            return ['status' => $status, 'retryable' => false, 'message' => ''];
        }

        // Redirects are refused (a redirect could point inside the network); 4xx are the receiver's decision.
        $retryable = $status >= 500 || $status === 408 || $status === 429;

        return ['status' => $status, 'retryable' => $retryable, 'message' => 'The endpoint answered '.$status.'.'];
    }
}
