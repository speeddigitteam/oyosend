<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels;

use App\Modules\Communication\Channels\Exceptions\InvalidWebhookSignature;
use SensitiveParameter;

/**
 * The common webhook check: a hex HMAC-SHA256 of the raw body under a shared secret, compared in constant time.
 * Adapters whose provider signs differently (timestamped, base64) implement their own and keep the same rule:
 * fail closed, before parsing.
 */
final class HmacSignature
{
    public static function sign(string $body, #[SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }

    /**
     * @throws InvalidWebhookSignature
     */
    public static function assertValid(string $body, ?string $signature, #[SensitiveParameter] string $secret): void
    {
        $signature = $signature === null ? '' : strtolower(trim($signature));

        if ($secret === '' || $signature === '' || ! hash_equals(self::sign($body, $secret), $signature)) {
            throw new InvalidWebhookSignature('The webhook signature is not valid.');
        }
    }
}
