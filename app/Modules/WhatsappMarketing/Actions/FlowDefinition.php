<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Modules\WhatsappMarketing\Models\Agent;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Validates and normalises a chatbot flow. A flow is DATA: a closed list of steps from a fixed vocabulary
 * (`reply`, `menu`, `tag`, `assign`, `handoff`). There is no code, no expression, no variable other than the
 * allow-listed placeholders, and no step that calls anything outside this app.
 */
final class FlowDefinition
{
    public const int MAX_ACTIONS = 10;

    public const int MAX_OPTIONS = 8;

    public const int MAX_OPTION_ACTIONS = 5;

    public const int MAX_KEYWORDS = 10;

    public function __construct(private readonly VariableResolver $variables) {}

    /**
     * @param  array<array-key, mixed>  $keywords
     * @return list<string>
     */
    public function keywords(array $keywords): array
    {
        $clean = [];

        foreach ($keywords as $keyword) {
            $value = self::normalize((string) $keyword);

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > 40) {
                throw ValidationException::withMessages(['keywords' => __('A keyword has up to 40 characters.')]);
            }

            $clean[$value] = $value;
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['keywords' => __('Add at least one keyword.')]);
        }

        if (count($clean) > self::MAX_KEYWORDS) {
            throw ValidationException::withMessages(['keywords' => __('A flow has up to :max keywords.', ['max' => self::MAX_KEYWORDS])]);
        }

        return array_values($clean);
    }

    /**
     * @param  array<array-key, mixed>  $actions
     * @return list<array<string, mixed>>
     */
    public function actions(array $actions): array
    {
        $actions = array_values($actions);

        if ($actions === []) {
            throw ValidationException::withMessages(['actions' => __('Add at least one step.')]);
        }

        if (count($actions) > self::MAX_ACTIONS) {
            throw ValidationException::withMessages(['actions' => __('A flow has up to :max steps.', ['max' => self::MAX_ACTIONS])]);
        }

        $clean = [];

        foreach ($actions as $index => $action) {
            $clean[] = $this->action($action, "actions.{$index}", allowMenu: true);
        }

        $menus = array_keys(array_filter($clean, static fn (array $step): bool => $step['type'] === 'menu'));

        if (count($menus) > 1 || ($menus !== [] && $menus[0] !== count($clean) - 1)) {
            throw ValidationException::withMessages(['actions' => __('A menu must be the last step, and a flow has one menu.')]);
        }

        return $clean;
    }

    /**
     * @return array<string, mixed>
     */
    private function action(mixed $action, string $path, bool $allowMenu): array
    {
        if (! is_array($action)) {
            throw ValidationException::withMessages([$path => __('This step is not valid.')]);
        }

        $type = (string) ($action['type'] ?? '');

        switch ($type) {
            case 'reply':
                $text = Text::clean((string) ($action['text'] ?? ''), 1000);

                if ($text === '') {
                    throw ValidationException::withMessages(["{$path}.text" => __('The reply text is required.')]);
                }

                $this->assertPlaceholders($text, "{$path}.text");

                return ['type' => 'reply', 'text' => $text];

            case 'tag':
                $tag = strtolower(trim((string) ($action['tag'] ?? '')));

                if (preg_match('/^[a-z0-9][a-z0-9_-]{0,29}$/', $tag) !== 1) {
                    throw ValidationException::withMessages(["{$path}.tag" => __('A tag uses lower-case letters, digits, dashes and underscores, up to 30 characters.')]);
                }

                return ['type' => 'tag', 'tag' => $tag];

            case 'assign':
                $assignee = $action['assignee_id'] ?? null;

                if ($assignee !== null && $assignee !== '') {
                    $valid = is_string($assignee) && Agent::query()->where('membership_id', $assignee)->where('is_active', true)->exists();

                    if (! $valid) {
                        throw ValidationException::withMessages(["{$path}.assignee_id" => __('Choose an enrolled, active agent.')]);
                    }
                }

                return ['type' => 'assign', 'assignee_id' => $assignee === '' ? null : $assignee];

            case 'handoff':
                return ['type' => 'handoff'];

            case 'menu':
                if (! $allowMenu) {
                    throw ValidationException::withMessages([$path => __('A menu cannot be nested.')]);
                }

                return $this->menu($action, $path);
        }

        throw ValidationException::withMessages(["{$path}.type" => __('Choose a step type.')]);
    }

    /**
     * @param  array<array-key, mixed>  $action
     * @return array<string, mixed>
     */
    private function menu(array $action, string $path): array
    {
        $prompt = Text::clean((string) ($action['prompt'] ?? ''), 500);

        if ($prompt === '') {
            throw ValidationException::withMessages(["{$path}.prompt" => __('The menu needs a question.')]);
        }

        $this->assertPlaceholders($prompt, "{$path}.prompt");

        $options = array_values((array) ($action['options'] ?? []));

        if ($options === [] || count($options) > self::MAX_OPTIONS) {
            throw ValidationException::withMessages(["{$path}.options" => __('A menu has 1 to :max options.', ['max' => self::MAX_OPTIONS])]);
        }

        $labels = [];
        $clean = [];

        foreach ($options as $index => $option) {
            $label = is_array($option) ? Text::clean((string) ($option['label'] ?? ''), 40, false) : '';

            if ($label === '' || isset($labels[mb_strtolower($label)])) {
                throw ValidationException::withMessages(["{$path}.options.{$index}.label" => __('Each option needs its own label of up to 40 characters.')]);
            }

            $labels[mb_strtolower($label)] = true;
            $steps = array_values((array) ($option['actions'] ?? []));

            if ($steps === [] || count($steps) > self::MAX_OPTION_ACTIONS) {
                throw ValidationException::withMessages(["{$path}.options.{$index}.actions" => __('Each option has 1 to :max steps.', ['max' => self::MAX_OPTION_ACTIONS])]);
            }

            $clean[] = [
                'label' => $label,
                'actions' => array_map(fn (mixed $step): array => $this->action($step, "{$path}.options.{$index}.actions", allowMenu: false), $steps),
            ];
        }

        return ['type' => 'menu', 'prompt' => $prompt, 'options' => $clean];
    }

    private function assertPlaceholders(string $text, string $path): void
    {
        $unknown = $this->variables->unknownPlaceholders($text);

        if ($unknown !== []) {
            throw ValidationException::withMessages([$path => __('Unknown placeholder: :name', ['name' => '{{'.$unknown[0].'}}'])]);
        }
    }

    /** Lower-case, single spaces, no surrounding punctuation noise: how keywords and messages are compared. */
    public static function normalize(string $text): string
    {
        $text = mb_strtolower(Text::clean($text, 500, false));

        return trim(preg_replace('/[\s]+/u', ' ', $text) ?? '');
    }
}
