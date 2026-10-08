<?php

declare(strict_types=1);

namespace App\Core\Automation\Contracts;

/**
 * Describes a step a workflow can perform.
 *
 * Actions are first-party classes. A workflow only references one by key and
 * supplies declarative input; it can never provide code to execute.
 */
interface ActionDefinition
{
    /**
     * Stable identifier, prefixed with the module key: `crm.lead.assign`.
     */
    public static function key(): string;

    /**
     * Translation key for the name shown in the workflow builder.
     */
    public static function label(): string;

    /**
     * Module that owns the action. The tenant must be entitled to it.
     */
    public static function module(): string;

    /**
     * Permission a user needs to add this action to a workflow.
     */
    public static function requiredPermission(): ?string;

    /**
     * JSON Schema of the action's input.
     *
     * @return array<string, mixed>
     */
    public static function inputSchema(): array;

    /**
     * JSON Schema of what the action returns to later steps.
     *
     * @return array<string, mixed>
     */
    public static function outputSchema(): array;

    /**
     * Run the action for the active tenant.
     *
     * Implementations must be idempotent for a given `$idempotencyKey`: the
     * engine retries failed steps and a retry must not repeat a side effect.
     *
     * @param  array<string, mixed>  $input  Already validated against the input schema.
     * @return array<string, mixed> Matching the output schema.
     */
    public function execute(array $input, string $idempotencyKey): array;
}
