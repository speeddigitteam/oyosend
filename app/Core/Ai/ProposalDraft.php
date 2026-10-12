<?php

declare(strict_types=1);

namespace App\Core\Ai;

/** A described, not yet applied change, shown to a person for confirmation. */
final readonly class ProposalDraft
{
    /**
     * @param  string  $summary  One line for the confirmation card.
     * @param  array<string, mixed>  $payload  Validated input for `ProposalTool::apply()`.
     * @param  list<string>  $recordIds  Records the change touches.
     */
    public function __construct(
        public string $summary,
        public array $payload,
        public array $recordIds = [],
    ) {}
}
