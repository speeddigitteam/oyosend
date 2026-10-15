<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

/**
 * Asks a workspace's PBX to place a bridge call: ring the member's own phone first, then the customer.
 * The softphone does not go through here; it talks SIP over WSS straight from the browser.
 */
interface PbxClient
{
    /**
     * @param  string  $callId  Our id for the call; the PBX reports events with it, so they find the row.
     * @param  string  $agent  Where to ring the member first: their extension, or their own phone in E.164.
     * @param  string  $number  The customer, in E.164.
     *
     * @throws PbxUnavailable When the PBX could not be asked or refused.
     */
    public function originateBridge(string $callId, string $agent, string $number): void;
}
