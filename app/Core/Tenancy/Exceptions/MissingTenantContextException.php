<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Exceptions;

use LogicException;

/**
 * Tenant-owned data was touched while no tenant was active.
 *
 * This is always a bug in the calling code (or a job that lost its tenant),
 * never something to recover from: failing loudly is what keeps an unscoped
 * query from reading every tenant's rows.
 */
final class MissingTenantContextException extends LogicException
{
    public function __construct(string $message = 'Tenant-owned data was accessed without an active tenant context.')
    {
        parent::__construct($message);
    }
}
