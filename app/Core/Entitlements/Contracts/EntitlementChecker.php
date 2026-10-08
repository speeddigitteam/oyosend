<?php

declare(strict_types=1);

namespace App\Core\Entitlements\Contracts;

use App\Core\Tenancy\Models\Tenant;

/**
 * Answers "may this tenant use this module?".
 *
 * It is the single gate in front of every module: routes, navigation, APIs,
 * scheduled jobs, widgets and automation triggers/actions all ask here, on
 * the server. The answer is never taken from the client.
 */
interface EntitlementChecker
{
    public function allows(Tenant $tenant, string $moduleKey): bool;

    /**
     * Keys of every registered module the tenant is entitled to.
     *
     * @return list<string>
     */
    public function entitledModules(Tenant $tenant): array;
}
