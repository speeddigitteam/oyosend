<?php

declare(strict_types=1);

namespace App\Modules\Communication\Channels\Contracts;

use App\Modules\Communication\Channels\Data\ChannelCredentials;
use App\Modules\Communication\Channels\Data\MediaTransfer;
use App\Modules\Communication\Models\ChannelConnection;

/**
 * A provider that can take a media file for later sending and give a customer's media back (WhatsApp Cloud API).
 * Like `send()`, neither method throws for provider failures: they answer a {@see MediaTransfer}. The size and
 * type decisions are the platform's (MediaLibrary); an adapter only moves bytes and refuses to read past `$maxBytes`.
 */
interface HandlesMedia
{
    /**
     * @param  string  $accountId  The provider account of the sending number (`phone_number_id`).
     */
    public function uploadMedia(ChannelConnection $connection, ChannelCredentials $credentials, string $accountId, string $bytes, string $mime, string $filename): MediaTransfer;

    /**
     * @param  string|null  $accountId  The receiving number, when known.
     */
    public function fetchMedia(ChannelConnection $connection, ChannelCredentials $credentials, string $providerMediaId, ?string $accountId, int $maxBytes): MediaTransfer;
}
