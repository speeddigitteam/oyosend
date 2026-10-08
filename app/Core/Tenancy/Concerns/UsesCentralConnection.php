<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Concerns;

/**
 * Pins a platform-level model (users, memberships, plans, ...) to the central
 * database, whatever connection the active tenant's business data uses.
 *
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 */
trait UsesCentralConnection
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
