<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

use RuntimeException;

/**
 * The provider could not answer. The message is generic by construction: it never carries the
 * credential, the request or the response body. `reason` is a short safe code (`unreachable`,
 * `unauthorized`, `rate_limited`, `http_error`, `bad_response`, `failed`).
 */
final class ProviderFailure extends RuntimeException
{
    public function __construct(string $message = '', public readonly string $reason = 'failed')
    {
        parent::__construct($message);
    }
}
