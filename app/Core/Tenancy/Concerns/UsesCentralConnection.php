<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Pins a platform-level model (users, memberships, plans, ...) to the central
 * database, whatever connection the active tenant's business data uses.
 *
 * @phpstan-require-extends Model
 */
trait UsesCentralConnection
{
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }
}
