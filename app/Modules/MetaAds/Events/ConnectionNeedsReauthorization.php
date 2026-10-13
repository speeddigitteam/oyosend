<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Events;

use App\Modules\MetaAds\Models\Connection;

/** Meta stopped accepting the stored authorisation; the person must authorise again. */
final class ConnectionNeedsReauthorization extends MetaAdsEvent
{
    public static function from(Connection $connection): self
    {
        return new self(['connection_id' => $connection->id]);
    }

    public static function name(): string
    {
        return 'meta_ads.connection.needs_reauthorization';
    }
}
