<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

use InvalidArgumentException;

/**
 * One component of a message template (header, body, footer or buttons), as a draft to submit or as the provider
 * returned it. Build with a named constructor: validation follows Meta's template rules (variables `{{1}}`...
 * are numbered without gaps, a body does not start or end with a variable, every variable has an example).
 */
final readonly class TemplateComponent
{
    public const string HEADER = 'header';

    public const string BODY = 'body';

    public const string FOOTER = 'footer';

    public const string BUTTONS = 'buttons';

    public const int MAX_BODY = 1024;

    public const int MAX_HEADER_TEXT = 60;

    public const int MAX_FOOTER = 60;

    /**
     * @param  string|null  $format  Header only: `text`, `image`, `video`, `document` or `location`.
     * @param  list<string>  $examples  Example values for the variables, in order.
     * @param  string|null  $headerHandle  Media header: the upload handle of an example file (from Meta's
     *                                     resumable upload API; this build does not obtain one for you).
     * @param  list<TemplateButton>  $buttons
     */
    private function __construct(
        public string $type,
        public ?string $format = null,
        public ?string $text = null,
        public array $examples = [],
        public ?string $headerHandle = null,
        public array $buttons = [],
    ) {}

    /** @param array<array-key, mixed> $examples One example value per variable of the body. */
    public static function body(string $text, array $examples = []): self
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX_BODY) {
            throw new InvalidArgumentException('A template body has 1 to 1024 characters.');
        }

        $count = self::variables($text);

        if ($count > 0 && (preg_match('/^\{\{\d+\}\}/', $text) === 1 || preg_match('/\{\{\d+\}\}$/', $text) === 1)) {
            throw new InvalidArgumentException('A template body cannot start or end with a variable.');
        }

        return new self(self::BODY, null, $text, self::examples($examples, $count));
    }

    /** @param array<array-key, mixed> $examples One example for the single variable, when the text has one. */
    public static function headerText(string $text, array $examples = []): self
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX_HEADER_TEXT) {
            throw new InvalidArgumentException('A template header text has 1 to 60 characters.');
        }

        $count = self::variables($text);

        if ($count > 1) {
            throw new InvalidArgumentException('A template header text has at most one variable.');
        }

        return new self(self::HEADER, 'text', $text, self::examples($examples, $count));
    }

    /** @param string $format `image`, `video` or `document`. */
    public static function headerMedia(string $format, string $exampleHandle): self
    {
        if (! in_array($format, ['image', 'video', 'document'], true)) {
            throw new InvalidArgumentException('A media header is an image, a video or a document.');
        }

        if ($exampleHandle === '' || strlen($exampleHandle) > 1000) {
            throw new InvalidArgumentException('A media header needs the upload handle of an example file.');
        }

        return new self(self::HEADER, $format, null, [], $exampleHandle);
    }

    public static function footer(string $text): self
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::MAX_FOOTER || str_contains($text, '{{')) {
            throw new InvalidArgumentException('A template footer has 1 to 60 characters and no variables.');
        }

        return new self(self::FOOTER, null, $text);
    }

    /** @param list<TemplateButton> $buttons */
    public static function buttons(array $buttons): self
    {
        if ($buttons === [] || count($buttons) > 10) {
            throw new InvalidArgumentException('A template has 1 to 10 buttons.');
        }

        $count = static fn (string $type): int => count(array_filter($buttons, static fn (TemplateButton $button): bool => $button->type === $type));

        if ($count(TemplateButton::URL) > 2 || $count(TemplateButton::PHONE_NUMBER) > 1 || $count(TemplateButton::COPY_CODE) > 1) {
            throw new InvalidArgumentException('A template has at most 2 URL buttons, 1 call button and 1 copy-code button.');
        }

        return new self(self::BUTTONS, null, null, [], null, $buttons);
    }

    /**
     * Rebuild a component as the provider returned it (no example is required on read).
     *
     * @param  list<string>  $examples
     * @param  list<TemplateButton>  $buttons
     */
    public static function fromProvider(string $type, ?string $format, ?string $text, array $examples = [], array $buttons = []): self
    {
        return new self($type, $format, $text, $examples, null, $buttons);
    }

    public static function variables(string $text): int
    {
        preg_match_all('/\{\{(\d+)\}\}/', $text, $matches);
        $numbers = array_map('intval', array_unique($matches[1]));
        sort($numbers);

        if ($numbers !== [] && $numbers !== range(1, count($numbers))) {
            throw new InvalidArgumentException('Template variables are numbered {{1}}, {{2}}, ... without gaps.');
        }

        // A stray "{{" that is not a numbered variable is refused by Meta; refuse it here.
        if (substr_count($text, '{{') !== count($matches[0])) {
            throw new InvalidArgumentException('Template variables are written {{1}}, {{2}}, ...');
        }

        return count($numbers);
    }

    /**
     * @param  array<array-key, mixed>  $examples
     * @return list<string>
     */
    private static function examples(array $examples, int $variables): array
    {
        $examples = array_values(array_map(static fn (mixed $value): string => trim((string) $value), $examples));

        if ($variables === 0) {
            return [];
        }

        if (count($examples) !== $variables || in_array('', $examples, true)) {
            throw new InvalidArgumentException('Every template variable needs a non-empty example value.');
        }

        foreach ($examples as $example) {
            if (mb_strlen($example) > 1024) {
                throw new InvalidArgumentException('An example value has up to 1024 characters.');
            }
        }

        return $examples;
    }
}
