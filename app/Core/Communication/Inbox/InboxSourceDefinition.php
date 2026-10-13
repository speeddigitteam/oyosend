<?php

declare(strict_types=1);

namespace App\Core\Communication\Inbox;

use InvalidArgumentException;

/** Who may see one inbox source and what it is called. */
final readonly class InboxSourceDefinition
{
    /**
     * @param  string  $key  `{module}.{source}`, unique across sources.
     * @param  string  $module  The module the workspace must be entitled to.
     * @param  string  $channel  A short channel name for the icon and filter: `whatsapp`, `email`.
     * @param  string  $label  English name; translated through `__()` when presented.
     * @param  string  $permission  The permission the member must hold.
     */
    public function __construct(
        public string $key,
        public string $module,
        public string $channel,
        public string $label,
        public string $permission,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key) !== 1 || ! str_starts_with($key, $module.'.')) {
            throw new InvalidArgumentException('An inbox source key is {module}.{source} in lower snake case.');
        }
    }
}
