<?php

declare(strict_types=1);

namespace App\Core\Ai\Text;

use InvalidArgumentException;

/**
 * Quoted tenant data handed to the model next to the task: a previous message, a product description, a brand
 * note. It is data, never an instruction: the model is told so and the text is encoded so it cannot close its
 * own block. Size-capped; longer text is cut.
 */
final readonly class ContextBlock
{
    public const int MAX_CHARS = 2000;

    public string $text;

    public bool $truncated;

    /**
     * @param  string  $label  What the data is, `[a-z_]{1,32}` (for example `previous_message`, `product`).
     */
    public function __construct(public string $label, string $text)
    {
        if (preg_match('/^[a-z][a-z_]{0,31}$/', $label) !== 1) {
            throw new InvalidArgumentException('A context block label must be 1-32 lowercase letters or underscores.');
        }

        $text = trim($text);
        $this->truncated = mb_strlen($text) > self::MAX_CHARS;
        $this->text = $this->truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text;
    }
}
