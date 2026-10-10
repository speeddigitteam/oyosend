<?php

declare(strict_types=1);

namespace App\Core\Contacts\Contracts;

/**
 * A module-owned participant in a shared-contact merge.
 *
 * Implementations run inside the contact merge transaction. They must only
 * move references owned by their module and must always constrain writes by
 * the supplied tenant id.
 */
interface ContactMergeReference
{
    /**
     * @return array<string, int> Reference counts for the merge audit entry.
     */
    public function merge(string $tenantId, string $canonicalId, string $duplicateId): array;
}
