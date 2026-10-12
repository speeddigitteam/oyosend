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
        // An IPv4 address written as IPv6 (::ffff:10.0.0.1) is judged as the IPv4 address it carries.
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Carrier-grade NAT (100.64.0.0/10) is not covered by the flags above.
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);

            return $long === false || ($long & 0xFFC00000) !== 0x64400000;
        }

        return true;
    }
}
