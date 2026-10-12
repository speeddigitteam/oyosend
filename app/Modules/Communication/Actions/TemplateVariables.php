<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use InvalidArgumentException;

/**
 * The closed list of placeholders a template may use. A template can never reach anything outside it.
 *
 * Other modules may add namespaced variables of their own from their provider's `boot` (for example
 * `invoice.number`), supplying the values when they ask the outbox to send. The list is code, not data.
 */
final class TemplateVariables
{
    /** @var array<string, string> key => English label */
    private array $variables = [
        'contact.first_name' => 'Contact first name',
        'contact.last_name' => 'Contact last name',
        'contact.full_name' => 'Contact full name',
        'contact.email' => 'Contact email',
        'contact.phone' => 'Contact phone',
        'company.name' => 'Company name',
        'workspace.name' => 'Workspace name',
        'sender.name' => 'Sender name',
    ];

    /** @param array<string, string> $variables key => English label */
    public function extend(array $variables): void
    {
        foreach ($variables as $key => $label) {
            if (preg_match('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', $key) !== 1) {
                throw new InvalidArgumentException("Template variable [{$key}] must look like `group.name`.");
            }

            $this->variables[$key] = $label;
        }
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->variables);
    }

    /** @return list<array{key: string, label: string}> */
    public function catalogue(): array
    {
        $list = [];

        foreach ($this->variables as $key => $label) {
            $list[] = ['key' => $key, 'label' => $label];
        }

        return $list;
    }

    public function has(string $key): bool
    {
        return isset($this->variables[$key]);
    }
}
