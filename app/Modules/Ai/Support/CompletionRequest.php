<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * One provider call, in a provider-neutral shape (content blocks as `text`, `tool_use` and `tool_result`).
 */
final readonly class CompletionRequest
{
    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  list<array{name: string, description: string, input_schema: array<string, mixed>}>  $tools
     */
    public function __construct(
        public string $system,
        public array $messages,
        public array $tools = [],
        public int $maxTokens = 1024,
    ) {}

    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     */
    public function with(array $messages, int $maxTokens): self
    {
        return new self($this->system, $messages, $this->tools, $maxTokens);
    }
}
