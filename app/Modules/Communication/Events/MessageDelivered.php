<?php

declare(strict_types=1);

namespace App\Modules\Communication\Events;

use App\Core\Events\TenantDomainEvent;
use App\Modules\Communication\Models\Message;

/** A provider confirmed delivery of a message. */
final class MessageDelivered extends TenantDomainEvent
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    private function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public static function from(Message $message): self
    {
        return new self([
            'message_id' => $message->id,
            'channel' => $message->channel->value,
            'purpose' => $message->purpose->value,
            'status' => $message->status->value,
            'contact_id' => $message->contact_id,
            'company_id' => $message->company_id,
            'membership_id' => $message->membership_id,
            'source' => $message->source,
            'failure_code' => $message->failure_code,
        ]);
    }

    public static function name(): string
    {
        return 'communication.message.delivered';
    }

    public function payload(): array
    {
        return $this->data;
    }
}
