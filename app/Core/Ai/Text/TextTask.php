<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

use InvalidArgumentException;

/**
 * One validated request for text. Structural fields are validated and rejected with an
 * InvalidArgumentException (a programming error); free text (input, context) is bounded: longer text is cut and
 * `truncated` says so.
 *
 * `purpose` identifies the task for metering and retries: the same purpose with the same task is counted once
 * and replayed from a short-lived tenant cache instead of calling the provider again. A caller that wants a
 * fresh alternative changes the purpose (for example appends an attempt number).
 */
final readonly class TextTask
{
    public const int MAX_INPUT_CHARS = 4000;

    public const int MAX_CONTEXT_BLOCKS = 5;

    public const int MAX_CONTEXT_TOTAL_CHARS = 6000;

    public const int MAX_LENGTH = 2000;

    public const int MAX_ITEMS = 30;

    public string $input;

    public bool $truncated;

    /** @var list<ContextBlock> */
    public array $context;

    /**
     * @param  string  $input  The text to work on (the post idea, the customer message, the text to translate).
     * @param  string  $purpose  Metering / idempotency key of the task, `[A-Za-z0-9:._-]{1,100}`.
     * @param  string|null  $locale  Language of the OUTPUT (`bn`, `en`, `bn-BD`); null keeps the language of the input.
     *                               Required for `translate`.
     * @param  int|null  $maxLength  Character cap for text kinds (1..2000).
     * @param  int|null  $maxItems  Number of hashtags (1..30).
     * @param  string|null  $platform  Where the text goes (`facebook`, `instagram`, `whatsapp`, ...), `[a-z0-9_]{1,32}`.
     * @param  array<array-key, mixed>  $context  ContextBlock instances: quoted data, at most 5 blocks, cut to 6000 characters in total.
     * @param  string|null  $actorMembershipId  The member who asked, for the per-member rate limit; null for system jobs.
     */
    public function __construct(
        public TextKind $kind,
        string $input,
        public string $purpose,
        public ?string $locale = null,
        public TextTone $tone = TextTone::Neutral,
        public ?int $maxLength = null,
        public ?int $maxItems = null,
        public ?string $platform = null,
        array $context = [],
        public ?string $actorMembershipId = null,
    ) {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('A text task needs input text.');
        }

        if (preg_match('/^[A-Za-z0-9:._-]{1,100}$/', $purpose) !== 1) {
            throw new InvalidArgumentException('A text task purpose must be 1-100 characters of letters, digits and : . _ -');
        }

        if ($locale !== null && preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
            throw new InvalidArgumentException('The locale must look like "bn" or "en-GB".');
        }

        if ($kind === TextKind::Translate && $locale === null) {
            throw new InvalidArgumentException('Translation needs a target locale.');
        }

        if ($maxLength !== null && ($maxLength < 1 || $maxLength > self::MAX_LENGTH)) {
            throw new InvalidArgumentException('maxLength must be between 1 and '.self::MAX_LENGTH.'.');
        }

        if ($maxItems !== null && ($maxItems < 1 || $maxItems > self::MAX_ITEMS)) {
            throw new InvalidArgumentException('maxItems must be between 1 and '.self::MAX_ITEMS.'.');
        }

        if ($platform !== null && preg_match('/^[a-z0-9_]{1,32}$/', $platform) !== 1) {
            throw new InvalidArgumentException('The platform must be 1-32 lowercase letters, digits or underscores.');
        }

        if (count($context) > self::MAX_CONTEXT_BLOCKS) {
            throw new InvalidArgumentException('At most '.self::MAX_CONTEXT_BLOCKS.' context blocks are accepted.');
        }

        $truncated = mb_strlen($input) > self::MAX_INPUT_CHARS;
        $this->input = $truncated ? mb_substr($input, 0, self::MAX_INPUT_CHARS) : $input;

        $remaining = self::MAX_CONTEXT_TOTAL_CHARS;
        $blocks = [];

        foreach ($context as $block) {
            if (! $block instanceof ContextBlock) {
                throw new InvalidArgumentException('Context entries must be ContextBlock instances.');
            }

            if ($remaining <= 0) {
                $truncated = true;

                break;
            }

            if (mb_strlen($block->text) > $remaining) {
                $block = new ContextBlock($block->label, mb_substr($block->text, 0, $remaining));
                $truncated = true;
            }

            $truncated = $truncated || $block->truncated;
            $remaining -= mb_strlen($block->text);
            $blocks[] = $block;
        }

        $this->context = $blocks;
        $this->truncated = $truncated;
    }

    /** A stable fingerprint of everything that shapes the output (not the actor). */
    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->kind->value,
            $this->input,
            $this->locale,
            $this->tone->value,
            $this->maxLength,
            $this->maxItems,
            $this->platform,
            array_map(static fn (ContextBlock $block): array => [$block->label, $block->text], $this->context),
        ], JSON_UNESCAPED_UNICODE));
    }
}
