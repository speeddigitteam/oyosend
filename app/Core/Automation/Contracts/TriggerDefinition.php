<?php

declare(strict_types=1);

namespace App\Core\Automation\Contracts;

use App\Core\Events\DomainEvent;

/**
 * Describes something that can start a workflow.
 *
 * A definition is metadata only - it never runs tenant-supplied code. The
 * automation engine (Phase 10) subscribes to {@see event()} and starts the
 * published workflows that use this trigger, after checking the tenant's
 * entitlement.
 */
interface TriggerDefinition
{
    /**
     * Stable identifier, prefixed with the module key: `crm.lead.created`.
     */
    public static function key(): string;

    /**
     * Translation key for the name shown in the workflow builder.
     */
    public static function label(): string;

    /**
     * Module that owns the trigger. The tenant must be entitled to it.
     */
    public static function module(): string;

    /**
     * Domain event that fires this trigger, or null for triggers the engine
     * raises itself (schedules, webhooks, manual runs).
     *
     * @return class-string<DomainEvent>|null
     */
    public static function event(): ?string;

    /**
     * Permission a user needs to build a workflow with this trigger.
     */
    public static function requiredPermission(): ?string;

    /**
     * JSON Schema of the trigger's configuration in the builder.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array;

    /**
     * JSON Schema of the data the trigger hands to the following steps.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array;
}
