<?php

declare(strict_types=1);

namespace App\Modules\Ai\Events;

use App\Core\Events\TenantDomainEvent;

/**
 * Shared shape of the AI domain events: a flat array of ids and scalars, dispatched after commit.
 * Payloads never carry conversation text, prompts or generated content.
 */
abstract class AiEvent extends TenantDomainEvent
{
    /** @param array<string, scalar|null> $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct();
    }

    public function payload(): array
    {
        return $this->data;
    }
}
