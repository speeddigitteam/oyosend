<?php

declare(strict_types=1);

namespace App\Modules\Ai\Support;

/**
 * A provider answer. `content` holds `text` and `tool_use` blocks.
 */
final readonly class CompletionResponse
{
    /**
     * @param  list<array<string, mixed>>  $content
     */
    public function __construct(
        public array $content,
        public string $stopReason,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
        public string $provider,
    ) {}

    public function text(): string
    {
        $parts = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        $uses = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                $input = $block['input'] ?? [];
                $uses[] = ['id' => $block['id'], 'name' => $block['name'], 'input' => is_array($input) ? $input : []];
            }
        }

        return $uses;
    }

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }
}
