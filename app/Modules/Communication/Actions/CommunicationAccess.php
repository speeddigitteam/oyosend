<?php

declare(strict_types=1);

namespace App\Modules\Communication\Actions;

use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Tenancy\TenantContext;

/**
 * The server-side gate for code that is reached without a route (a contract call from another module, a
 * queued send, a scheduled job): is the active workspace entitled to the communication app?
 */
final class CommunicationAccess
{
    public const string MODULE = 'communication';

    public function __construct(
        private readonly TenantContext $context,
        private readonly EntitlementChecker $entitlements,
    ) {}

    public function entitled(): bool
    {
        $tenant = $this->context->tenant();

        return $tenant !== null && $this->entitlements->allows($tenant, self::MODULE);
    }
}
