<?php

declare(strict_types=1);

namespace App\Core\Communication\Data\Template;

use InvalidArgumentException;

/** One button of a message template, validated against Meta's rules. Build with a named constructor. */
final readonly class TemplateButton
{
    public const string QUICK_REPLY = 'quick_reply';

    public const string URL = 'url';

    public const string PHONE_NUMBER = 'phone_number';

    public const string COPY_CODE = 'copy_code';

    private function __construct(
        public string $type,
        public string $text,
        public ?string $url = null,
        public ?string $phoneNumber = null,
        public ?string $example = null,
    ) {}

    public static function quickReply(string $text): self
    {
        return new self(self::QUICK_REPLY, self::text($text));
    }

    /**
     * @param  string|null  $example  Required when the URL ends with the variable `{{1}}`: a complete example URL.
     */
    public static function url(string $text, string $url, ?string $example = null): self
    {
        $scheme = strtolower((string) parse_url(str_replace('{{1}}', 'x', $url), PHP_URL_SCHEME));

        if ($scheme !== 'https' && $scheme !== 'http' || mb_strlen($url) > 2000) {
            throw new InvalidArgumentException('A URL button needs an absolute http(s) address of up to 2000 characters.');
        }

        $variables = substr_count($url, '{{');

        if ($variables > 1 || ($variables === 1 && ! str_ends_with($url, '{{1}}'))) {
            throw new InvalidArgumentException('A URL button may end with one variable {{1}}, nowhere else.');
        }

        if ($variables === 1 && ($example === null || $example === '' || mb_strlen($example) > 2000)) {
            throw new InvalidArgumentException('A URL button with a variable needs an example URL.');
        }

        return new self(self::URL, self::text($text), $url, null, $variables === 1 ? $example : null);
    }

    public static function phoneNumber(string $text, string $phoneNumber): self
    {
        if (preg_match('/^\+[1-9]\d{6,14}$/', $phoneNumber) !== 1) {
            throw new InvalidArgumentException('A call button needs an international phone number such as +8801700000000.');
        }

        return new self(self::PHONE_NUMBER, self::text($text), null, $phoneNumber);
    }

    /** A button that copies a code to the clipboard; `example` is a sample code of up to 15 characters. */
    public static function copyCode(string $example): self
    {
        if ($example === '' || mb_strlen($example) > 15) {
            throw new InvalidArgumentException('A copy-code example has 1 to 15 characters.');
        }

        return new self(self::COPY_CODE, 'Copy code', null, null, $example);
    }

    /** Rebuild a button as the provider returned it (no validation: it is a read, not a submission). */
    public static function fromProvider(string $type, string $text, ?string $url = null, ?string $phoneNumber = null, ?string $example = null): self
    {
        return new self($type, $text, $url, $phoneNumber, $example);
    }

    private static function text(string $text): string
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 25) {
            throw new InvalidArgumentException('A template button text has 1 to 25 characters.');
        }

        return $text;
    }
}
