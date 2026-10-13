<?php

declare(strict_types=1);

namespace App\Core\Contacts\Records;

/** What one module shows about one contact. */
final readonly class CustomerSection
{
    /**
     * @param  list<CustomerItem>  $items  At most the query's limit, newest or most relevant first.
     * @param  int  $total  Every record the member may see, not only the items shown.
     * @param  string|null  $viewAllUrl  The module's own list, when it can show this contact's records.
     * @param  list<CustomerAction>  $actions
     */
    public function __construct(
        public array $items,
        public int $total,
        public ?string $viewAllUrl = null,
        public array $actions = [],
    ) {}
}
