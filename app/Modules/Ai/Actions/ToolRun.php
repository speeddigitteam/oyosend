<?php

declare(strict_types=1);

namespace App\Modules\Ai\Actions;

use App\Modules\Ai\Models\Proposal;

/**
 * The outcome of running one tool for the assistant. `json` is exactly what the model is shown (wrapped,
 * size-limited, redacted later by the gateway); `status` and `recordIds` are what the trail keeps.
 */
final readonly class ToolRun
{
    /**
     * @param  list<string>  $recordIds
     */
    public function __construct(
        public string $tool,
        public string $status,
        public string $json,
        public array $recordIds = [],
        public ?Proposal $proposal = null,
        public bool $failed = false,
    ) {}

    /**
     * What is stored on the assistant message: names and ids, never content.
     *
     * @return array<string, mixed>
     */
    public function trace(): array
    {
        return array_filter([
            'tool' => $this->tool,
            'status' => $this->status,
            'record_ids' => $this->recordIds,
            'proposal_id' => $this->proposal?->id,
        ], static fn ($value): bool => $value !== null && $value !== []);
    }
}
