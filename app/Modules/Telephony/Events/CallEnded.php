<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Telephony\Models\Call;

/** A call reached its final state (completed, missed, busy or failed). Ids and scalars only. */
final class CallEnded extends TenantDomainEvent
{
    public readonly string $callId;

    public readonly string $direction;

    public readonly string $status;

    public readonly string $remoteNumber;

    public readonly ?string $contactId;

    public readonly ?string $ownerId;

    public readonly int $durationSeconds;

    public function __construct(Call $call)
    {
        parent::__construct();
        $this->callId = $call->id;
        $this->direction = $call->direction->value;
        $this->status = $call->status->value;
        $this->remoteNumber = $call->remote_number;
        $this->contactId = $call->contact_id;
        $this->ownerId = $call->owner_id;
        $this->durationSeconds = $call->duration_seconds ?? 0;
    }

    public static function name(): string
    {
        return 'telephony.call.ended';
    }

    public function payload(): array
    {
        return [
            'call_id' => $this->callId,
            'direction' => $this->direction,
            'status' => $this->status,
            'remote_number' => $this->remoteNumber,
            'contact_id' => $this->contactId,
            'owner_id' => $this->ownerId,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
