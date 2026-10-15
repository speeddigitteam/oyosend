<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

use App\Modules\Telephony\Enums\PbxDriver;
use App\Modules\Telephony\Models\TelephonySetting;
use Illuminate\Contracts\Container\Container;

/** Picks the client for a workspace's PBX. `fake` resolves only where the fake is bound (local and testing). */
final class PbxClients
{
    public function __construct(
        private readonly Container $app,
        private readonly PbxUrlGuard $guard,
    ) {}

    public function for(TelephonySetting $settings): ?PbxClient
    {
        if (! $settings->bridgeReady()) {
            return null;
        }

        return match ($settings->driver) {
            PbxDriver::Asterisk => new AsteriskAriClient($settings, $this->guard),
            PbxDriver::Fake => $this->app->bound(FakePbxClient::class) ? $this->app->make(FakePbxClient::class) : null,
        };
    }
}
