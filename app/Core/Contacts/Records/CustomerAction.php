<?php

declare(strict_types=1);

namespace App\Core\Contacts\Records;

/** A link into the owning module's own screen, offered only to a member who may use it. */
final readonly class CustomerAction
{
    /**
     * @param  string  $key  Stable within its section, such as `create_lead`.
     * @param  string  $label  English label; translated through `__()` when presented.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $url,
    ) {}
}
