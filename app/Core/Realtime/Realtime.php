<?php

declare(strict_types=1);

namespace App\Core\Realtime;

/**
 * Whether realtime updates are switched on, and what a browser needs to connect (ADR 0035).
 *
 * Realtime is infrastructure, like the queue or the database connection: it is configured in the environment
 * (`BROADCAST_CONNECTION=reverb` and the `REVERB_*` values), never in the database. It is on only when the default
 * broadcast connection is `reverb` and its key, secret, app id and the address browsers use are all set and well
 * formed. Anything else, including an unconfigured install and the test suite, is "off": nothing is broadcast, the
 * channel authorization route answers 404 and pages keep polling.
 *
 * The browser gets the public key and the address only. The secret never leaves the server.
 */
final class Realtime
{
    private const string CONNECTION = 'reverb';

    /** A host name or an IPv4 address: nothing that could end a CSP directive or carry a path. */
    private const string HOST = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    /** Reverb app keys and ids are generated as letters and digits; this also keeps them out of any markup. */
    private const string TOKEN = '/^[A-Za-z0-9_-]{1,128}$/';

    public function enabled(): bool
    {
        return $this->client() !== null;
    }

    /**
     * What the page's websocket client needs, or null when realtime is off.
     *
     * @return array{key: string, host: string, port: int, scheme: 'http'|'https'}|null
     */
    public function client(): ?array
    {
        if (config('broadcasting.default') !== self::CONNECTION) {
            return null;
        }

        $connection = config('broadcasting.connections.'.self::CONNECTION);

        if (! is_array($connection)) {
            return null;
        }

        foreach (['key', 'app_id'] as $field) {
            if (! is_string($connection[$field] ?? null) || preg_match(self::TOKEN, $connection[$field]) !== 1) {
                return null;
            }
        }

        if (! is_string($connection['secret'] ?? null) || $connection['secret'] === '') {
            return null;
        }

        $client = is_array($connection['client'] ?? null) ? $connection['client'] : [];
        $host = $client['host'] ?? null;
        $port = filter_var($client['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $scheme = $client['scheme'] ?? null;

        if (! is_string($host) || preg_match(self::HOST, $host) !== 1 || $port === false || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return ['key' => $connection['key'], 'host' => $host, 'port' => $port, 'scheme' => $scheme];
    }

    /** The websocket origin browsers connect to (`ws://host:port` or `wss://host:port`), for the CSP `connect-src`. */
    public function socketOrigin(): ?string
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        return ($client['scheme'] === 'https' ? 'wss' : 'ws').'://'.$client['host'].':'.$client['port'];
    }
}
