<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine\Outbound;

/**
 * Decides whether an outbound webhook URL is safe to call (server-side request forgery guard).
 *
 * https only, port 443, no credentials in the URL, no IP-literal host, and every address the name resolves
 * to must be a public one: loopback, private, link-local and reserved ranges are refused. The caller pins
 * the connection to the addresses returned here, so the name cannot be re-pointed between check and call.
 */
final class UrlGuard
{
    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Cheap, offline checks used when an endpoint is saved.
     */
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

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return 'Only the default https port is allowed.';
        }

        if (filter_var($parts['host'], FILTER_VALIDATE_IP) !== false || str_contains($parts['host'], '[')) {
            return 'Use a host name, not an IP address.';
        }

        if (! str_contains($parts['host'], '.') || preg_match('/(^|\.)(localhost|local|internal|lan|home)$/i', $parts['host']) === 1) {
            return 'The host must be a public name.';
        }

        return null;
    }

    /**
     * Full check at call time, including DNS.
     *
     * @return array{host: string, addresses: list<string>}|string The pinned addresses, or the reason it was refused.
     */
    public function inspect(string $url): array|string
    {
        if (($problem = $this->syntaxProblem($url)) !== null) {
            return $problem;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $addresses = $this->resolver->resolve($host);

        if ($addresses === []) {
            return 'The host name could not be resolved.';
        }

        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return 'The host resolves to a private or reserved address.';
            }
        }

        return ['host' => $host, 'addresses' => $addresses];
    }

    private function isPublic(string $address): bool
    {
        $packed = @inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            return $this->isPublicV4($address);
        }

        $embedded = $this->embeddedV4($packed);

        if ($embedded === false) {
            return false;
        }

        if ($embedded !== null) {
            // An IPv4 address carried inside IPv6 is judged as the IPv4 address it carries.
            return $this->isPublicV4($embedded);
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * The IPv4 address an IPv6 address carries, null when it carries none, false when the range is refused
     * outright (prefixes that may reach any IPv4 host through a gateway we do not control).
     *
     * Judged as IPv4: IPv4-mapped ::ffff:0:0/96 (dotted or hex form), IPv4-compatible ::/96, NAT64
     * 64:ff9b::/96 and 6to4 2002::/16. Refused: local-use NAT64 64:ff9b:1::/48 and Teredo 2001::/32.
     */
    private function embeddedV4(string $packed): string|false|null
    {
        $v4 = static fn (string $bytes): string => (string) inet_ntop($bytes);

        // ::ffff:a.b.c.d
        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return $v4(substr($packed, 12, 4));
        }

        // ::/96 (this also covers :: and ::1, which come out as 0.0.0.x and are refused as reserved).
        if (str_starts_with($packed, str_repeat("\0", 12))) {
            return $v4(substr($packed, 12, 4));
        }

        // 64:ff9b::/96
        if (str_starts_with($packed, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
            return $v4(substr($packed, 12, 4));
        }

        // 64:ff9b:1::/48
        if (str_starts_with($packed, "\x00\x64\xff\x9b\x00\x01")) {
            return false;
        }

        // 2002::/16, the IPv4 address is in bits 16 to 47.
        if (str_starts_with($packed, "\x20\x02")) {
            return $v4(substr($packed, 2, 4));
        }

        // 2001::/32
        if (str_starts_with($packed, "\x20\x01\x00\x00")) {
            return false;
        }

        return null;
    }

    private function isPublicV4(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        $long = ip2long($address);

        if ($long === false) {
            return false;
        }

        // Not covered by the flags above: carrier-grade NAT 100.64/10, IETF protocol 192.0.0/24,
        // benchmarking 198.18/15 and the documentation ranges.
        foreach ([
            [0x64400000, 0xFFC00000],
            [0xC0000000, 0xFFFFFF00],
            [0xC0000200, 0xFFFFFF00],
            [0xC6120000, 0xFFFE0000],
            [0xC6336400, 0xFFFFFF00],
            [0xCB007100, 0xFFFFFF00],
        ] as [$network, $mask]) {
            if (($long & $mask) === $network) {
                return false;
            }
        }

        return true;
    }
}
