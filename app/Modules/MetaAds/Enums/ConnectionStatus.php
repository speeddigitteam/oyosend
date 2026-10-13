<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Enums;

/** connected -> needs_reauthorization -> (authorise again) -> connected; disconnected discards the token. */
enum ConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsReauthorization = 'needs_reauthorization';
    case Disconnected = 'disconnected';
}
