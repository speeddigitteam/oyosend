<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Support;

use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises the structured content of an email: a list of typed blocks with fixed fields.
 *
 * There is no free HTML anywhere. A block carries plain text (with `**bold**`, `*italic*` and
 * `[label](https://link)` as the only formatting), URLs that pass {@see SafeUrl}, and small enumerations. HTML is
 * produced by {@see EmailRenderer}, which escapes everything. Unknown block types, unknown fields (dropped),
 * unknown variables and unsafe URLs are validation errors on the exact block.
 */
final class ContentBlocks
{
    public const int MAX_BLOCKS = 50;

    public const int MAX_TEXT = 5000;

    public const array TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer'];

    public const string LINK_PATTERN = '/\[([^\[\]\r\n]{1,200})\]\(([^\s)]{1,1000})\)/';

    /**
     * @return list<array<string, mixed>>
     *
     * @throws ValidationException
     */
    public static function normalize(mixed $blocks, string $field = 'blocks'): array
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            throw ValidationException::withMessages([$field => __('The email content is not valid.')]);
        }

        if ($blocks === []) {
            throw ValidationException::withMessages([$field => __('Add at least one block to the email.')]);
        }

        if (count($blocks) > self::MAX_BLOCKS) {
            throw ValidationException::withMessages([$field => __('An email can have at most :count blocks.', ['count' => self::MAX_BLOCKS])]);
        }

        $errors = [];
        $clean = [];

        foreach ($blocks as $index => $block) {
            $path = $field.'.'.$index;

            if (! is_array($block) || ! is_string($block['type'] ?? null) || ! in_array($block['type'], self::TYPES, true)) {
                $errors[$path] = __('This block type is not supported.');

                continue;
            }

            $result = match ($block['type']) {
                'heading' => self::heading($block, $path, $errors),
                'text' => self::text($block, $path, $errors),
                'image' => self::image($block, $path, $errors),
                'button' => self::button($block, $path, $errors),
                'divider' => ['type' => 'divider'],
                default => self::spacer($block),
            };

            $clean[] = $result;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if (strlen((string) json_encode($clean)) > 60000) {
            throw ValidationException::withMessages([$field => __('The email content is too long.')]);
        }

        return $clean;
    }

    /**
     * A subject line: one line, up to 200 characters, personalisation variables except the unsubscribe link.
     *
     * @throws ValidationException
     */
    public static function subject(mixed $subject, string $field = 'subject', bool $required = true): ?string
    {
        $text = self::line($subject);

        if ($text === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => __('A subject is required.')]);
            }

            return null;
        }

        if (mb_strlen($text) > 200) {
            throw ValidationException::withMessages([$field => __('The subject is too long.')]);
        }

        $allowed = array_values(array_diff(Variables::ALLOWED, [Variables::UNSUBSCRIBE]));

        if (Variables::unknown($text, $allowed) !== []) {
            throw ValidationException::withMessages([$field => __('This subject uses a variable that is not available.')]);
        }

        return $text;
    }

    /** Collapse a value to one line without control characters. */
    public static function line(mixed $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($value) ? (string) $value : ''));
    }

    /** Text with newlines kept, other control characters removed. */
    public static function multiline(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim((string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/u', '', $text));
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<array-key, mixed>|string>  $errors
     * @return array<string, mixed>
     */
    private static function heading(array $block, string $path, array &$errors): array
    {
        $text = self::line($block['text'] ?? '');
        $level = (int) ($block['level'] ?? 2);

        if ($text === '' || mb_strlen($text) > 160) {
            $errors[$path.'.text'] = __('A heading needs text of up to 160 characters.');
        } elseif (Variables::unknown($text) !== []) {
            $errors[$path.'.text'] = __('This text uses a variable that is not available.');
        }

        return ['type' => 'heading', 'text' => $text, 'level' => min(3, max(1, $level)), 'align' => self::align($block)];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<array-key, mixed>|string>  $errors
     * @return array<string, mixed>
     */
    private static function text(array $block, string $path, array &$errors): array
    {
        $text = self::multiline($block['text'] ?? '');

        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            $errors[$path.'.text'] = __('A text block needs text of up to :count characters.', ['count' => self::MAX_TEXT]);
        } elseif (Variables::unknown($text) !== []) {
            $errors[$path.'.text'] = __('This text uses a variable that is not available.');
        } else {
            preg_match_all(self::LINK_PATTERN, $text, $links);

            foreach ($links[2] as $url) {
                if (! SafeUrl::isAllowed($url, true, true)) {
                    $errors[$path.'.text'] = __('A link in this text is not allowed. Use https:// addresses only.');

                    break;
                }
            }
        }

        return ['type' => 'text', 'text' => $text, 'align' => self::align($block)];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<array-key, mixed>|string>  $errors
     * @return array<string, mixed>
     */
    private static function image(array $block, string $path, array &$errors): array
    {
        $url = self::line($block['url'] ?? '');
        $alt = self::line($block['alt'] ?? '');
        $link = self::line($block['link_url'] ?? '');

        if (! SafeUrl::isSecureImage($url)) {
            $errors[$path.'.url'] = __('An image needs an https:// address.');
        }

        if ($alt === '' || mb_strlen($alt) > 200) {
            $errors[$path.'.alt'] = __('Describe the image in up to 200 characters.');
        }

        if ($link !== '' && ! SafeUrl::isAllowed($link)) {
            $errors[$path.'.link_url'] = __('The link is not allowed. Use an https:// address.');
        }

        return [
            'type' => 'image', 'url' => $url, 'alt' => $alt, 'link_url' => $link === '' ? null : $link,
            'width' => min(600, max(100, (int) ($block['width'] ?? 600))),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, array<array-key, mixed>|string>  $errors
     * @return array<string, mixed>
     */
    private static function button(array $block, string $path, array &$errors): array
    {
        $label = self::line($block['label'] ?? '');
        $url = self::line($block['url'] ?? '');
        $style = ($block['style'] ?? 'primary') === 'dark' ? 'dark' : 'primary';

        if ($label === '' || mb_strlen($label) > 60) {
            $errors[$path.'.label'] = __('A button needs a label of up to 60 characters.');
        } elseif (Variables::unknown($label, array_values(array_diff(Variables::ALLOWED, [Variables::UNSUBSCRIBE]))) !== []) {
            $errors[$path.'.label'] = __('This text uses a variable that is not available.');
        }

        if (! SafeUrl::isAllowed($url, true, true)) {
            $errors[$path.'.url'] = __('The link is not allowed. Use an https:// address.');
        }

        return ['type' => 'button', 'label' => $label, 'url' => $url, 'align' => self::align($block), 'style' => $style];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function spacer(array $block): array
    {
        return ['type' => 'spacer', 'height' => min(96, max(8, (int) ($block['height'] ?? 24)))];
    }

    /** @param  array<string, mixed>  $block */
    private static function align(array $block): string
    {
        return ($block['align'] ?? 'left') === 'center' ? 'center' : 'left';
    }
}
