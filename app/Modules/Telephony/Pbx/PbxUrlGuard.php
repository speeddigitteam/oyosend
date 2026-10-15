<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

/**
 * Decides whether a workspace's PBX API URL is safe for the server to call (server-side request forgery guard).
 *
 * https only, no credentials in the URL, any port (ARI listens on 8089), and every address the host resolves to must
 * be public unless `telephony.allow_private_pbx` is on. The caller pins the connection to the addresses returned,
 * so the name cannot be re-pointed between the check and the call.
 */
final class PbxUrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /** Offline checks, used when the settings are saved. */
    public function syntaxProblem(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'The URL is not valid.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'Only https URLs are allowed.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The URL must not contain credentials.';
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            return 'The URL must not contain a query or a fragment.';
        }

        return null;
    }

    /**
     * The full check, at call time, including DNS.
     *
     * @return array{host: string, port: int, addresses: list<string>}|string The pinned addresses, or why it was refused.
     */
    public function inspect(string $url): array|string
    {
        if (($problem = $this->syntaxProblem($url)) !== null) {
            return $problem;
        }

        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        // An address written in the URL is the address: never ask DNS about it.
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->resolver->resolve($host);

        if ($addresses === []) {
            return 'The host name could not be resolved.';
        }

        if (! (bool) config('telephony.allow_private_pbx')) {
            foreach ($addresses as $address) {
                if (! $this->isPublic($address)) {
                    return 'The host resolves to a private or reserved address.';
                }
            }
        }

        return ['host' => $host, 'port' => $port, 'addresses' => $addresses];
    }

    public function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 16) {
            // An IPv4 address carried inside IPv6 (mapped or compatible) is judged as that IPv4 address.
            if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, str_repeat("\0", 12))) {
                return $this->isPublic((string) inet_ntop(substr($packed, 12, 4)));
            }

            // NAT64, 6to4 and Teredo can reach any IPv4 host through a gateway: refused.
            if (str_starts_with($packed, "\x00\x64\xff\x9b") || str_starts_with($packed, "\x20\x02") || str_starts_with($packed, "\x20\x01\x00\x00")) {
                return false;
            }

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $long = (int) ip2long($address);

        // Carrier-grade NAT, IETF protocol, benchmarking and documentation ranges.
        foreach ([[0x64400000, 0xFFC00000], [0xC0000000, 0xFFFFFF00], [0xC0000200, 0xFFFFFF00], [0xC6120000, 0xFFFE0000], [0xC6336400, 0xFFFFFF00], [0xCB007100, 0xFFFFFF00]] as [$network, $mask]) {
            if (($long & $mask) === $network) {
                return false;
            }
        }

        return true;
    }
}
