<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

use App\Modules\Telephony\Models\TelephonySetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asterisk REST Interface (ARI): `POST {api_url}/ari/channels` originates a `Local/{agent}@{context}` channel; once
 * the member answers, the dialplan continues to `{number}` in the same context. The channel id is our call id, so
 * the PBX's events name our row. Basic authentication with the workspace's ARI user; the secret is never logged.
 *
 * Never exercised against a live Asterisk yet (ADR 0036).
 */
final class AsteriskAriClient implements PbxClient
{
    public function __construct(
        private readonly TelephonySetting $settings,
        private readonly PbxUrlGuard $guard,
    ) {}

    public function originateBridge(string $callId, string $agent, string $number): void
    {
        $base = rtrim((string) $this->settings->api_url, '/');
        $inspection = $this->guard->inspect($base);

        if (is_string($inspection)) {
            Log::warning('telephony.pbx.refused_url', ['reason' => $inspection]);

            throw new PbxUnavailable('The phone system address is not allowed.');
        }

        $context = $this->settings->origination_context ?: 'from-internal';
        $query = array_filter([
            'endpoint' => 'Local/'.$agent.'@'.$context,
            'extension' => $number,
            'context' => $context,
            'priority' => '1',
            'channelId' => $callId,
            'callerId' => $this->settings->caller_id,
            'timeout' => '30',
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        $options = ['allow_redirects' => false];

        if (defined('CURLOPT_RESOLVE')) {
            $options['curl'] = [CURLOPT_RESOLVE => array_map(
                static fn (string $address): string => $inspection['host'].':'.$inspection['port'].':'.$address,
                $inspection['addresses'],
            )];
        }

        try {
            $response = Http::withBasicAuth((string) $this->settings->api_username, (string) $this->settings->api_secret)
                ->withOptions($options)
                ->acceptJson()
                ->timeout((int) config('telephony.pbx_timeout', 10))
                ->post($base.'/ari/channels?'.http_build_query($query));
        } catch (Throwable) {
            Log::warning('telephony.pbx.unreachable', ['call' => $callId]);

            throw new PbxUnavailable('The phone system could not be reached.');
        }

        if (! $response->successful()) {
            Log::warning('telephony.pbx.refused', ['call' => $callId, 'status' => $response->status()]);

            throw new PbxUnavailable('The phone system refused the call.');
        }
    }
}
