<?php

declare(strict_types=1);

namespace App\Core\Social\Data;

/**
 * One Facebook Page a workspace has connected in the Social app. Read-only and token-free: an id the
 * workspace can use to refer to it, its name, the platform's own page id and whether it still works.
 */
final readonly class ConnectedPage
{
    public const string STATUS_CONNECTED = 'connected';

    public const string STATUS_NEEDS_REAUTHORIZATION = 'needs_reauthorization';

    /**
     * @param  string  $id  The Social app's id for the connected account (opaque to callers).
     * @param  string  $externalPageId  The page id on Facebook.
     * @param  string  $status  `connected` or `needs_reauthorization`.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $externalPageId,
        public string $status,
    ) {}

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
