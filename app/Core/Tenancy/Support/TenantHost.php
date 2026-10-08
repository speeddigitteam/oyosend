<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Support;

use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Knows how hostnames map to the central site, the admin panel and tenants.
 */
final class TenantHost
{
    /** Pattern a tenant subdomain label must match (RFC 1123, lower case). */
    public const string LABEL_PATTERN = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    public function domain(): string
    {
        return (string) config('platform.domain');
    }

    public function adminHost(): string
    {
        return config('platform.admin_subdomain').'.'.$this->domain();
    }

    /**
     * Route pattern for the `{tenant}` domain parameter. The look-ahead keeps
     * reserved hosts (admin, www, ...) from ever matching a tenant route.
     */
    public function routePattern(): string
    {
        $reserved = implode('|', array_map(
            static fn (string $label): string => preg_quote($label, '/'),
            $this->reserved(),
        ));

        return '(?!(?:'.$reserved.')\.)'.self::LABEL_PATTERN;
    }

    /**
     * The tenant lookup key for a hostname, or null when the host is not a
     * tenant subdomain of this platform.
     */
    public function tenantKey(string $host): ?string
    {
        $host = Str::lower($host);
        $suffix = '.'.$this->domain();

        if (! Str::endsWith($host, $suffix)) {
            return null;
        }

        $label = Str::beforeLast($host, $suffix);

        if ($label === '' || str_contains($label, '.') || $this->isReserved($label)) {
            return null;
        }

        // `D`: without it `$` would also accept a label that ends in a line break.
        return preg_match('/^'.self::LABEL_PATTERN.'$/D', $label) === 1 ? $label : null;
    }

    public function isReserved(string $label): bool
    {
        return in_array(Str::lower($label), $this->reserved(), true);
    }

    /**
     * Absolute URL into a tenant's workspace, built from the central APP_URL
     * so the scheme and port always match how the platform is served.
     */
    public function url(Tenant|string $tenant, string $path = '/'): string
    {
        $slug = $tenant instanceof Tenant ? $tenant->slug : $tenant;
        $base = parse_url((string) config('app.url'));
        $scheme = $base['scheme'] ?? 'https';
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        return $scheme.'://'.$slug.'.'.$this->domain().$port.'/'.ltrim($path, '/');
    }

    /**
     * @return list<string>
     */
    private function reserved(): array
    {
        /** @var list<string> $reserved */
        $reserved = config('platform.reserved_subdomains', []);

        return array_values(array_unique([...$reserved, (string) config('platform.admin_subdomain')]));
    }
}
