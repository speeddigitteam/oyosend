<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers\Cloud;

use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Where and how the Cloud API adapter talks to the Graph API. The base URL and the default version are operator
 * configuration (`config/communication.php`, `whatsapp.graph_base_url` / `whatsapp.api_version`); a workspace may
 * pin a version of its own on the connection (`graph_api_version`, `v<major>.<minor>`), never a host.
 */
final class CloudGraph
{
    public static function base(): string
    {
        return rtrim((string) config('communication.whatsapp.graph_base_url', 'https://graph.facebook.com'), '/');
    }

    public static function version(ChannelConnection $connection): string
    {
        $settings = $connection->settings ?? [];

        return is_string($settings['graph_api_version'] ?? null) && preg_match('/^v\d{1,2}\.\d$/', $settings['graph_api_version']) === 1
            ? $settings['graph_api_version']
            : (string) config('communication.whatsapp.api_version', 'v21.0');
    }

    public static function url(ChannelConnection $connection, string $path): string
    {
        return self::base().'/'.self::version($connection).'/'.ltrim($path, '/');
    }

    public static function http(#[SensitiveParameter] string $token, int $timeout = 15): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout($timeout);
    }

    /** A provider's own error text, clipped. Untrusted: shown to operators, never evaluated. */
    public static function clip(mixed $text, int $max = 300): ?string
    {
        return is_string($text) && $text !== '' ? mb_substr($text, 0, $max) : null;
    }
}
