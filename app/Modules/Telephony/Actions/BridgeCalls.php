<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Actions;

use App\Core\Identity\Models\TenantMembership;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallMode;
use App\Modules\Telephony\Models\Call;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Pbx\PbxClients;
use App\Modules\Telephony\Pbx\PbxUnavailable;

/**
 * A call through the member's own phone: the PBX rings them first (their own phone if they set one, else their
 * extension), then the customer. For a member who has no softphone open, or whose computer has no microphone.
 */
final class BridgeCalls
{
    public function __construct(
        private readonly TelephonySettings $settings,
        private readonly PbxClients $clients,
        private readonly CallLog $log,
    ) {}

    /** @throws PbxUnavailable With a message that is safe to show. */
    public function start(TenantMembership $member, string $number): Call
    {
        $client = $this->clients->for($this->settings->current())
            ?? throw new PbxUnavailable('Calls through your own phone are not set up for this workspace.');

        $extension = Extension::query()->where('membership_id', $member->id)->first()
            ?? throw new PbxUnavailable('You have no extension yet. Ask an administrator to give you one.');

        $call = $this->log->start($member, $number, CallDirection::Outbound, CallMode::Bridge, $extension->extension);

        try {
            $client->originateBridge($call->id, $extension->bridge_phone ?? $extension->extension, $number);
        } catch (PbxUnavailable $exception) {
            $this->log->fail($call);

            throw $exception;
        }

        return $call;
    }
}
