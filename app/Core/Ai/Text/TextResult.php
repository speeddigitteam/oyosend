<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

/**
 * The answer to a TextTask. It carries text only: no action was taken and nothing was sent. `message` is
 * safe to show a person; raw provider errors and secrets never appear in it.
 *
 * Fields by kind: every kind fills `text`; `hashtags` fills `hashtags` (normalised, lowercase, no `#`);
 * `sentiment` fills `sentiment`, `scoreBasisPoints` (-10000 very negative .. 10000 very positive) and
 * `language` (ISO 639 code or null).
 */
final readonly class TextResult
{
    /**
     * @param  list<string>  $hashtags
     */
    public function __construct(
        public TextState $state,
        public TextKind $kind,
        public string $text = '',
        public array $hashtags = [],
        public ?Sentiment $sentiment = null,
        public ?int $scoreBasisPoints = null,
        public ?string $language = null,
        public string $message = '',
        public bool $retryable = false,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public string $promptVersion = '',
        public bool $replayed = false,
    ) {}

    public static function unavailable(TextState $state, TextKind $kind, ?string $message = null, bool $retryable = false): self
    {
        return new self($state, $kind, message: $message ?? $state->message(), retryable: $retryable);
    }

    public function ok(): bool
    {
        return $this->state === TextState::Ok;
    }

    /** True for generated text: callers label it as AI-generated in their UI. */
    public function aiGenerated(): bool
    {
        return $this->ok();
    }

    public function asReplay(): self
    {
        return new self(
            $this->state, $this->kind, $this->text, $this->hashtags, $this->sentiment, $this->scoreBasisPoints,
            $this->language, $this->message, $this->retryable, $this->inputTokens, $this->outputTokens,
            $this->promptVersion, true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'kind' => $this->kind->value,
            'text' => $this->text,
            'hashtags' => $this->hashtags,
            'sentiment' => $this->sentiment?->value,
            'score_basis_points' => $this->scoreBasisPoints,
            'language' => $this->language,
            'message' => $this->message,
            'retryable' => $this->retryable,
            'ai_generated' => $this->aiGenerated(),
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'prompt_version' => $this->promptVersion,
            'replayed' => $this->replayed,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data  Output of toArray(); anything malformed returns null.
     */
    public static function fromArray(array $data): ?self
    {
        $state = TextState::tryFrom((string) ($data['state'] ?? ''));
        $kind = TextKind::tryFrom((string) ($data['kind'] ?? ''));

        if ($state === null || $kind === null) {
            return null;
        }

        $hashtags = is_array($data['hashtags'] ?? null)
            ? array_values(array_filter($data['hashtags'], 'is_string'))
            : [];

        return new self(
            $state,
            $kind,
            (string) ($data['text'] ?? ''),
            $hashtags,
            isset($data['sentiment']) ? Sentiment::tryFrom((string) $data['sentiment']) : null,
            isset($data['score_basis_points']) ? (int) $data['score_basis_points'] : null,
            isset($data['language']) ? (string) $data['language'] : null,
            (string) ($data['message'] ?? ''),
            (bool) ($data['retryable'] ?? false),
            (int) ($data['input_tokens'] ?? 0),
            (int) ($data['output_tokens'] ?? 0),
            (string) ($data['prompt_version'] ?? ''),
            (bool) ($data['replayed'] ?? false),
        );
    }
}
