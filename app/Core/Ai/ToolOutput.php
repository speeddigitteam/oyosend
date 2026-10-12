<?php

declare(strict_types=1);

namespace App\Core\Ai;

/**
 * What a tool returns. `recordIds` is what the audit trail stores (ids, never content).
 */
final readonly class ToolOutput
{
    /**
     * @param  array<string, mixed>  $data  JSON-encodable; any text in it is untrusted record content.
     * @param  list<string>  $recordIds  Ids of the records read or described.
     */
    public function __construct(
        public array $data,
        public array $recordIds = [],
        public ?ProposalDraft $proposal = null,
    ) {}
}
