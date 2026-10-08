<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Http\Controllers;

use App\Core\Dashboard\DashboardBuilder;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(
        Request $request,
        DashboardBuilder $dashboard,
        ModuleRegistry $modules,
        EntitlementChecker $entitlements,
        TenantContext $context,
    ): Response {
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Core/Pages/Dashboard', [
            'widgets' => $dashboard->for($user),
            // Optional apps this workspace is entitled to - not every app in
            // the build. Until the App Store grants any, this is honestly zero.
            'installedApps' => count(array_filter(
                $entitlements->entitledModules($context->require()),
                static fn (string $key): bool => $modules->find($key)?->core === false,
            )),
        ]);
    }
}
