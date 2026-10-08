<?php

declare(strict_types=1);

namespace App\Core\Dashboard\Http\Controllers;

use App\Core\Dashboard\DashboardBuilder;
use App\Core\Identity\Models\User;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRegistry;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(Request $request, DashboardBuilder $dashboard, ModuleRegistry $modules): Response
    {
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Core/Pages/Dashboard', [
            'widgets' => $dashboard->for($user),
            // Optional apps arrive with the App Store; until then this is honestly zero.
            'installedApps' => count(array_filter(
                $modules->all(),
                static fn (ModuleManifest $manifest): bool => ! $manifest->core,
            )),
        ]);
    }
}
