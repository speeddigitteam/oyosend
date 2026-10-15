<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Pbx;

/**
 * A PBX that remembers what it was asked and rings nobody. Bound only in the local and testing environments
 * (TelephonyServiceProvider), and chosen only by a workspace whose driver is `fake`.
 */
final class FakePbxClient implements PbxClient
{
    /** @var list<array{call_id: string, agent: string, number: string}> */
    public array $originated = [];

    public bool $refuse = false;

    public function originateBridge(string $callId, string $agent, string $number): void
    {
        if ($this->refuse) {
            throw new PbxUnavailable('The phone system refused the call.');
        }

        $this->originated[] = ['call_id' => $callId, 'agent' => $agent, 'number' => $number];
    }
}
