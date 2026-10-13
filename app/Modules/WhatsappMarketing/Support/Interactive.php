<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Support;

use Illuminate\Validation\ValidationException;

/**
 * The choices an interactive WhatsApp message offers: up to three reply buttons, or a list of up to ten rows
 * behind one button. Only labels are kept; the customer's choice comes back as the label's text, so a menu answer
 * is matched like a typed one. The limits are Meta's (button title 20, row title 24, list button 20 characters,
 * body 1024), checked here and again by the provider adapter.
 */
final readonly class Interactive
{
    public const string BUTTONS = 'buttons';

    public const string LIST = 'list';

    public const int MAX_BUTTONS = 3;

    public const int BUTTON_TITLE = 20;

    public const int MAX_ROWS = 10;

    public const int ROW_TITLE = 24;

    public const int LIST_BUTTON = 20;

    public const int MAX_BODY = 1024;

    /** @param list<string> $options */
    private function __construct(
        public string $kind,
        public array $options,
        public ?string $button,
    ) {}

    /**
     * What a person composed in the inbox: `{kind, button?, options[]}`.
     *
     * @throws ValidationException
     */
    public static function fromInput(mixed $input, string $field = 'interactive'): self
    {
        if (! is_array($input)) {
            throw ValidationException::withMessages([$field => __('Choose buttons or a list.')]);
        }

        $kind = $input['kind'] ?? null;

        if ($kind !== self::BUTTONS && $kind !== self::LIST) {
            throw ValidationException::withMessages(["{$field}.kind" => __('Choose buttons or a list.')]);
        }

        $options = self::labels($input['options'] ?? null, $kind === self::LIST ? self::ROW_TITLE : self::BUTTON_TITLE, "{$field}.options");

        if ($kind === self::BUTTONS) {
            if (count($options) > self::MAX_BUTTONS) {
                throw ValidationException::withMessages(["{$field}.options" => __('A message has up to :max buttons.', ['max' => self::MAX_BUTTONS])]);
            }

            return new self(self::BUTTONS, $options, null);
        }

        if (count($options) > self::MAX_ROWS) {
            throw ValidationException::withMessages(["{$field}.options" => __('A list has up to :max options.', ['max' => self::MAX_ROWS])]);
        }

        $button = Text::clean(is_string($input['button'] ?? null) ? $input['button'] : '', 200, false);

        if ($button === '' || mb_strlen($button) > self::LIST_BUTTON) {
            throw ValidationException::withMessages(["{$field}.button" => __('The list button needs a label of up to :max characters.', ['max' => self::LIST_BUTTON])]);
        }

        return new self(self::LIST, $options, $button);
    }

    /**
     * The best way to offer a chatbot menu: buttons for up to three short labels, a list for up to ten labels
     * that fit a row, or null (the menu is sent as numbered text).
     *
     * @param  list<string>  $labels
     */
    public static function forMenu(array $labels, string $listButton): ?self
    {
        $labels = array_values(array_filter(array_map(static fn (string $label): string => Text::clean($label, 200, false), $labels), static fn (string $label): bool => $label !== ''));
        $longest = $labels === [] ? 0 : max(array_map('mb_strlen', $labels));

        if ($labels === [] || count(array_unique(array_map('mb_strtolower', $labels))) !== count($labels)) {
            return null;
        }

        if (count($labels) <= self::MAX_BUTTONS && $longest <= self::BUTTON_TITLE) {
            return new self(self::BUTTONS, $labels, null);
        }

        $button = mb_substr(Text::clean($listButton, 200, false), 0, self::LIST_BUTTON);

        if (count($labels) <= self::MAX_ROWS && $longest <= self::ROW_TITLE && $button !== '') {
            return new self(self::LIST, $labels, $button);
        }

        return null;
    }

    /**
     * Rebuild from what a message stored, or null when it carries no valid choices.
     *
     * @param  array<array-key, mixed>|null  $stored
     */
    public static function fromStored(?array $stored): ?self
    {
        if ($stored === null) {
            return null;
        }

        try {
            return self::fromInput($stored);
        } catch (ValidationException) {
            return null;
        }
    }

    /** @return array{kind: string, button: string|null, options: list<string>} */
    public function toArray(): array
    {
        return ['kind' => $this->kind, 'button' => $this->button, 'options' => $this->options];
    }

    /** The provider hint `whatsapp_interactive`: compact JSON, well under the 2000-byte hint limit. */
    public function hint(): string
    {
        return (string) json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return list<string>
     *
     * @throws ValidationException
     */
    private static function labels(mixed $options, int $max, string $field): array
    {
        if (! is_array($options) || $options === []) {
            throw ValidationException::withMessages([$field => __('Add at least one option.')]);
        }

        $clean = [];

        foreach (array_values($options) as $index => $option) {
            $label = Text::clean(is_string($option) ? $option : '', 200, false);

            if ($label === '' || mb_strlen($label) > $max) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('Each option needs a label of up to :max characters.', ['max' => $max])]);
            }

            if (isset($clean[mb_strtolower($label)])) {
                throw ValidationException::withMessages(["{$field}.{$index}" => __('Each option needs its own label.')]);
            }

            $clean[mb_strtolower($label)] = $label;
        }

        return array_values($clean);
    }
}
