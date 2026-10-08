<?php

declare(strict_types=1);

namespace App\Core\Tenancy\Support;

use App\Core\Tenancy\TenantContext;

/**
 * Decides which database connection the active tenant's business data uses.
 *
 * Today every tenant shares the central database, so this returns null (the
 * model's own default). It is the one seam through which a tenant with a
 * `db_connection` is routed to a dedicated database - see docs/DATABASE.md.
 */
final class TenantConnectionResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(): ?string
    {
        $connection = $this->context->tenant()?->db_connection;

        if ($connection === null || $connection === '') {
            return null;
        }

        // Never route to a connection that is not configured: a typo here must
        // not fall through to some other tenant's database.
        return config()->has("database.connections.{$connection}") ? $connection : null;
    }
}
