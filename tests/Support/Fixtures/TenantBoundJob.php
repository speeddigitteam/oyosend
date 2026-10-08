<?php

declare(strict_types=1);

namespace Tests\Support\Fixtures;

use App\Core\Tenancy\Contracts\RequiresTenantContext;

/**
 * A queued job that must never run outside a tenant.
 */
final class TenantBoundJob extends RecordsTenantJob implements RequiresTenantContext {}
