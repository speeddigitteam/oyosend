<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Providers\Cloud;

use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\MediaTransfer;
use App\Modules\Communication\Models\ChannelConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Media on the Cloud API.
 *
 *  - Upload: `POST /{version}/{phone_number_id}/media`, multipart `file` + `messaging_product=whatsapp` + `type`
 *    (the MIME type); answers `{"id": "<media id>"}`.
 *  - Download: `GET /{version}/{media_id}` answers `{url, mime_type, sha256, file_size, id}`, then a GET of that
 *    `url` with the same bearer token. The URL is provider-supplied, so it is only followed when it is https, a
 *    named host (no IP literal, no port) under one of `communication.whatsapp.media_hosts`: a hostile or
 *    compromised answer can never make this server fetch an internal address. Redirects are not followed.
 *
 * Never logs or returns the credential.
 */
final class CloudMedia
{
    public static function upload(ChannelConnection $connection, ChannelCredentials $credentials, string $accountId, string $bytes, string $mime, string $filename): MediaTransfer
    {
        if (preg_match('/^\d{5,32}$/', $accountId) !== 1) {
            return MediaTransfer::failed(MediaTransfer::REJECTED);
        }

        try {
            $response = CloudGraph::http($credentials->secret(), 60)
                ->attach('file', $bytes, $filename === '' ? 'file' : $filename, ['Content-Type' => $mime])
                ->post(CloudGraph::url($connection, $accountId.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime]);
        } catch (ConnectionException) {
            return MediaTransfer::failed(MediaTransfer::UNREACHABLE);
        } catch (Throwable) {
            return MediaTransfer::failed(MediaTransfer::UNAVAILABLE);
        }

        if (! $response->successful()) {
            return MediaTransfer::failed(self::failure($response));
        }

        $id = $response->json('id');

        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) === 1
            ? MediaTransfer::uploaded($id)
            : MediaTransfer::failed(MediaTransfer::UNAVAILABLE);
    }

    public static function fetch(ChannelConnection $connection, ChannelCredentials $credentials, string $providerMediaId, ?string $accountId, int $maxBytes): MediaTransfer
    {
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/', $providerMediaId) !== 1) {
            return MediaTransfer::failed(MediaTransfer::REJECTED);
        }

        $query = $accountId !== null && preg_match('/^\d{5,32}$/', $accountId) === 1 ? ['phone_number_id' => $accountId] : [];

        try {
            $meta = CloudGraph::http($credentials->secret())->get(CloudGraph::url($connection, $providerMediaId), $query);
        } catch (ConnectionException) {
            return MediaTransfer::failed(MediaTransfer::UNREACHABLE);
        } catch (Throwable) {
            return MediaTransfer::failed(MediaTransfer::UNAVAILABLE);
        }

        if (! $meta->successful()) {
            return MediaTransfer::failed(self::failure($meta));
        }

        $url = $meta->json('url');
        $size = $meta->json('file_size');

        if (! is_string($url) || ! self::allowedHost($url)) {
            return MediaTransfer::failed(MediaTransfer::REJECTED);
        }

        if (is_numeric($size) && (int) $size > $maxBytes) {
            return MediaTransfer::failed(MediaTransfer::TOO_LARGE);
        }

        try {
            $file = CloudGraph::http($credentials->secret(), 60)
                ->withOptions([
                    'allow_redirects' => false,
                    // Refuse before the body is read when the server says it is larger than the cap.
                    'on_headers' => static function ($response) use ($maxBytes): void {
                        if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                            throw new RuntimeException('too_large');
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException $exception) {
            return MediaTransfer::failed(str_contains($exception->getMessage(), 'too_large') ? MediaTransfer::TOO_LARGE : MediaTransfer::UNREACHABLE);
        } catch (Throwable $exception) {
            return MediaTransfer::failed(str_contains($exception->getMessage(), 'too_large') ? MediaTransfer::TOO_LARGE : MediaTransfer::UNAVAILABLE);
        }

        if (! $file->successful()) {
            return MediaTransfer::failed(self::failure($file));
        }

        $bytes = $file->body();

        if (strlen($bytes) > $maxBytes) {
            return MediaTransfer::failed(MediaTransfer::TOO_LARGE);
        }

        $mime = $meta->json('mime_type');
        $sha = $meta->json('sha256');

        return MediaTransfer::downloaded(
            $bytes,
            is_string($mime) ? mb_substr($mime, 0, 100) : null,
            is_string($sha) && preg_match('/^[A-Fa-f0-9]{64}$/', $sha) === 1 ? strtolower($sha) : null,
            $providerMediaId,
        );
    }

    private static function allowedHost(string $url): bool
    {
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.')) {
            return false;
        }

        foreach ((array) config('communication.whatsapp.media_hosts', []) as $suffix) {
            $suffix = strtolower((string) $suffix);

            if ($suffix !== '' && ($host === $suffix || str_ends_with($host, '.'.$suffix))) {
                return true;
            }
        }

        return false;
    }

    private static function failure(Response $response): string
    {
        $status = $response->status();
        $code = $response->json('error.code');

        return match (true) {
            $status === 401 || $code === 190 => MediaTransfer::AUTH,
            $status === 429 || $status >= 500 || in_array($code, [4, 17, 80007, 130429], true) => MediaTransfer::UNAVAILABLE,
            default => MediaTransfer::REJECTED,
        };
    }
}
