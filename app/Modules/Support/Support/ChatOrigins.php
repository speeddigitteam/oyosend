<?php

declare(strict_types=1);

namespace App\Modules\Support\Support;

/**
 * The website origins a workspace allows to embed its chat. They become the frame's CSP `frame-ancestors` list, so
 * only a canonical `scheme://host[:port]` is accepted: no path, query, credentials, wildcard or IP address.
 */
final class ChatOrigins
{
    private const string PATTERN = '~^(https?)://([a-z0-9.-]{1,253})(?::(\d{1,5}))?/?$~D';

    private const string LABEL = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D';

    /**
     * The canonical origin, or null when the value is not one the widget may be embedded on. `https` is required;
     * plain `http` is accepted only for `localhost` and `*.localhost` while the application runs locally.
     */
    public static function normalize(string $value, bool $local): ?string
    {
        if (preg_match(self::PATTERN, strtolower(trim($value)), $match) !== 1) {
            return null;
        }

        [, $scheme, $host] = $match;
        $port = isset($match[3]) ? (int) $match[3] : null;

        if (! self::validHost($host) || ($port !== null && ($port < 1 || $port > 65535))) {
            return null;
        }

        $loopbackName = $host === 'localhost' || str_ends_with($host, '.localhost');

        if ($scheme === 'http' && ! ($local && $loopbackName)) {
            return null;
        }

        if ($port === ($scheme === 'https' ? 443 : 80)) {
            $port = null;
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }

    /**
     * Canonical, de-duplicated origins of a list that was validated with {@see normalize()}.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public static function normalizeAll(array $values, bool $local): array
    {
        $origins = [];

        foreach ($values as $value) {
            $origin = self::normalize($value, $local);

            if ($origin !== null && ! in_array($origin, $origins, true)) {
                $origins[] = $origin;
            }
        }

        return $origins;
    }

    private static function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);

        // A single label is only acceptable as `localhost`; anything else needs a domain.
        if (count($labels) === 1 && $host !== 'localhost') {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match(self::LABEL, $label) !== 1) {
                return false;
            }
        }

        // A top-level label of digits only would be an IP address in disguise.
        return preg_match('/^\d+$/', (string) end($labels)) !== 1;
    }
}
