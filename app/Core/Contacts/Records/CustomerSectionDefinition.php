<?php

declare(strict_types=1);

namespace App\Core\Contacts\Records;

use InvalidArgumentException;

/** Who may see one Customer 360 section and where it is placed. */
final readonly class CustomerSectionDefinition
{
    /**
     * @param  string  $key  `{module}.{section}`, unique across providers.
     * @param  string  $module  The module the workspace must be entitled to.
     * @param  string  $label  English title; translated through `__()` when presented.
     * @param  string  $permission  The permission the member must hold.
     * @param  int  $order  Sections are shown in ascending order.
     */
    public function __construct(
        public string $key,
        public string $module,
        public string $label,
        public string $permission,
        public int $order = 100,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException('A customer section key is {module}.{section} in lower snake case.');
        }

        if (! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException('A customer section key starts with its module.');
        }
    }
}
