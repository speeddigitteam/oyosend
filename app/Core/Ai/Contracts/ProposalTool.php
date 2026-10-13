<?php

declare(strict_types=1);

namespace App\Core\Ai\Contracts;

use App\Core\Ai\ToolContext;

/**
 * A tool that would change data. `execute()` validates and DESCRIBES the change (a `ProposalDraft` in
 * the output) and writes nothing. Only after the member explicitly confirms does {@see apply()} run, as
 * that member, through the module's normal action (validation, authorization, audit, domain events).
 */
interface ProposalTool extends AiTool
{
    /**
     * Apply a confirmed proposal. The permission and the record's scope are checked again here: what was
     * allowed when the proposal was made may not be allowed now.
     *
     * @param  array<string, mixed>  $payload  The payload of the stored proposal.
     * @return array<string, scalar|null> Ids and scalars describing the result (no free text).
     */
    public function apply(ToolContext $context, array $payload): array;

    /**
     * The values a stored proposal would write, for the confirmation card: the person must see what is
     * being written, not only which fields. Plain text, already length-capped; `old` is the current value
     * (null for a new record). Returns [] when the record can no longer be shown to this member.
     *
     * @param  array<string, mixed>  $payload  The payload of the stored proposal.
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    public function changes(ToolContext $context, array $payload): array;
}
