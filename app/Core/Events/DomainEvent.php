<?php

declare(strict_types=1);

namespace App\Core\Events;

use Carbon\CarbonImmutable;

/**
 * Something that happened in a tenant's business, announced to other modules.
 *
 * Conventions:
 *  - named in the past tense, `{module}.{entity}.{verb}`: `crm.lead.created`;
 *  - raised only after the database transaction that caused it has committed;
 *  - carries ids and scalars, never live models, so it can cross a queue and
 *    be handed to a workflow without leaking internals;
 *  - modules react to each other's events and never call each other's models.
 */
interface DomainEvent
{
    /**
     * Stable, dotted event name.
     */
    public static function name(): string;

    public function tenantId(): string;

    public function occurredAt(): CarbonImmutable;

    /**
     * Automation-safe data describing what happened.
     *
     * @return array<string, scalar|array<array-key, mixed>|null>
     */
    public function payload(): array;
}
