<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Actions;

use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Modules\WhatsappMarketing\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Fills template variables and quick-reply placeholders from a closed list of fields. A campaign maps each
 * numbered template variable to one of {@see FIELDS} or to a fixed text; nothing is an expression.
 */
final class VariableResolver
{
    /** @var list<string> The only fields a variable or placeholder can read. */
    public const array FIELDS = [
        'contact.first_name', 'contact.last_name', 'contact.full_name', 'company.name', 'workspace.name', 'agent.name',
    ];

    /**
     * The values of every field for one contact.
     *
     * @return array<string, string>
     */
    public function valuesFor(?Contact $contact, ?Company $company, string $workspaceName, ?string $agentName = null): array
    {
        return [
            'contact.first_name' => $contact === null ? '' : Text::clean($contact->first_name, 120, false),
            'contact.last_name' => $contact === null ? '' : Text::clean((string) $contact->last_name, 120, false),
            'contact.full_name' => $contact === null ? '' : Text::clean($contact->fullName(), 240, false),
            'company.name' => $company === null ? '' : Text::clean($company->name, 160, false),
            'workspace.name' => Text::clean($workspaceName, 160, false),
            'agent.name' => Text::clean((string) $agentName, 120, false),
        ];
    }

    /**
     * Validate and normalise a campaign's variable mapping for a template with `$count` variables.
     *
     * @param  array<array-key, mixed>  $input  position => {type: field|text, field?|value?}
     * @return array<int, array{type: string, field?: string, value?: string}> Keyed by position, from 1.
     */
    public function normalizeMap(array $input, int $count): array
    {
        $map = [];

        for ($position = 1; $position <= $count; $position++) {
            $entry = $input[$position] ?? null;
            $type = is_array($entry) ? ($entry['type'] ?? null) : null;
            $field = is_array($entry) ? ($entry['field'] ?? null) : null;
            $value = is_array($entry) && is_string($entry['value'] ?? null) ? Text::clean($entry['value'], 200, false) : '';

            if ($type === 'field' && is_string($field) && in_array($field, self::FIELDS, true)) {
                $map[$position] = ['type' => 'field', 'field' => $field];
            } elseif ($type === 'text' && $value !== '') {
                $map[$position] = ['type' => 'text', 'value' => $value];
            } else {
                throw ValidationException::withMessages(["variables.{$position}" => __('Choose where variable {{:position}} comes from.', ['position' => $position])]);
            }
        }

        return $map;
    }

    /**
     * @param  array<array-key, array{type?: string, field?: string, value?: string}>  $map  Keyed by position.
     * @param  array<string, string>  $values  From {@see valuesFor()}.
     * @return list<string> The body parameters, `{{1}}` first.
     */
    public function parameters(array $map, array $values): array
    {
        $parameters = [];

        for ($position = 1; $position <= count($map); $position++) {
            $entry = $map[$position] ?? $map[(string) $position] ?? [];

            $parameters[] = ($entry['type'] ?? null) === 'field'
                ? ($values[$entry['field'] ?? ''] ?? '')
                : (string) ($entry['value'] ?? '');
        }

        return $parameters;
    }

    /**
     * Replace `{{contact.first_name}}`-style placeholders in free text. Single pass: a value that looks like a
     * placeholder is not expanded again. Unknown placeholders are left as typed.
     *
     * @param  array<string, string>  $values
     */
    public function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/',
            static fn (array $match): string => in_array($match[1], self::FIELDS, true) ? ($values[$match[1]] ?? '') : $match[0],
            $text,
        );
    }

    /**
     * Placeholders in a text that are not in the allow-list.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+\.[a-z_]+)\s*\}\}/', $text, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => ! in_array($name, self::FIELDS, true),
        )));
    }
}
