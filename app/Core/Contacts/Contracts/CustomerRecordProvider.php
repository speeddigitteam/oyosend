<?php

declare(strict_types=1);

namespace App\Core\Contacts\Contracts;

use App\Core\Contacts\Records\CustomerQuery;
use App\Core\Contacts\Records\CustomerSection;
use App\Core\Contacts\Records\CustomerSectionDefinition;

/**
 * One section of the Customer 360 view: what a module holds about one shared contact (ADR 0020).
 *
 * A module registers providers from its own service provider by tagging the classes
 * (`$this->app->tag([MySection::class], CustomerRecordProvider::TAG)`) and imports nothing of another module. The
 * registry runs a provider only when the workspace is entitled to `definition()->module` and the member holds
 * `definition()->permission` (read from the database, never from the request).
 *
 * A provider is READ-ONLY over the module's own records:
 *
 *  - it never changes data;
 *  - when its permission is scopable it narrows records with `RecordAccess` for `$query->user`;
 *  - it returns at most `$query->limit` items, newest or most relevant first, and the full count in `total`;
 *  - it offers an action only when the member may use it, and the action's URL is the module's own page;
 *  - "not reported" is `null`, not an empty section.
 */
interface CustomerRecordProvider
{
    /** Container tag under which providers are registered. */
    public const string TAG = 'contacts.customer_record_providers';

    public function definition(): CustomerSectionDefinition;

    public function forContact(CustomerQuery $query): ?CustomerSection;
}
