<?php

declare(strict_types=1);

namespace App\Modules\Automation\Engine\Outbound;

use App\Modules\Automation\Support\Limits;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Hard limit on how much of an endpoint's response is ever downloaded. The body is never used (only the
 * status), so a receiver answering with an endless or huge body must not be able to fill memory or disk:
 * the transfer is aborted once the limit is passed, whether or not a Content-Length was announced.
 */
final class ResponseCap
{
    public const int DEFAULT_MAX_BYTES = 65536;

    /** The status line, kept so an aborted transfer can still be judged by it. */
    public int $status = 0;

    public bool $exceeded = false;

    private readonly int $maxBytes;

    /** @param  int|null  $maxBytes  Defaults to `automation.limits.outbound_response_bytes`, or 64 KB when unset. */
    public function __construct(?int $maxBytes = null)
    {
        $configured = $maxBytes ?? Limits::int('outbound_response_bytes');

        $this->maxBytes = $configured > 0 ? $configured : self::DEFAULT_MAX_BYTES;
    }

    /** Guzzle `on_headers`. */
    public function onHeaders(ResponseInterface $response): void
    {
        $this->status = $response->getStatusCode();
        $length = $response->getHeaderLine('Content-Length');

        if ($length !== '' && ctype_digit($length) && (int) $length > $this->maxBytes) {
            $this->exceeded = true;

            throw new RuntimeException('The response is larger than allowed.');
        }
    }

    /** Guzzle `progress`: bytes downloaded so far. */
    public function onProgress(int $downloadTotal, int $downloaded): void
    {
        if ($downloaded > $this->maxBytes) {
            $this->exceeded = true;

            throw new RuntimeException('The response is larger than allowed.');
        }
    }
}
