<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Billing\Enums\LimitKind;
use App\Core\Modules\AppOffer;
use App\Core\Modules\LimitDefinition;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Modules\Reports\Actions\SavedReportManager;
use App\Modules\Reports\Models\SavedReport;

/**
 * Reports & Analytics (category Reporting): one place to run, save, share and export the reports
 * that other apps publish through `App\Core\Reporting\Contracts\ReportProvider`.
 *
 * The app owns no business data. It depends on `core` only, imports no other module's class, and
 * runs a provider only when the workspace is entitled to the provider's module and the member
 * holds the provider's own permission (narrowed to the member's record scope by the provider).
 */
final class ReportsServiceProvider extends ModuleServiceProvider
{
    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'reports',
            name: 'Reports & Analytics',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('reports.reports.view', 'reports'),
                new PermissionDefinition('reports.reports.export', 'reports', sensitive: true),
                new PermissionDefinition('reports.saved.manage', 'reports'),
                new PermissionDefinition('reports.saved.manage_all', 'reports', sensitive: true),
            ],
            navigation: [
                new NavigationItem('reports', 'reports.index', 'chart-no-axes-combined', 'reports.reports.view', 'apps', 70, 'reports.*'),
            ],
            morphMap: ['reports_saved_report' => SavedReport::class],
            limits: [
                new LimitDefinition(
                    SavedReportManager::LIMIT,
                    LimitKind::Gauge,
                    static fn (): int => SavedReport::query()->count(),
                ),
            ],
            description: 'Run, save, share and export reports across the apps your workspace uses, with each person seeing only what their permissions and record scope allow.',
            category: AppCategory::Reporting,
            icon: 'chart-no-axes-combined',
            featured: false,
            features: [
                'One catalogue of reports from the apps you have installed',
                'Choose columns and filters, with bounded date ranges',
                'Saved reports, private or shared with a role or department',
                'CSV export that is safe to open in a spreadsheet and is recorded in the audit trail',
                'Every report respects the viewer\'s own permission and record scope',
            ],
            offers: [new AppOffer('free', 'Free', AppBillingType::Free)],
            releaseNotes: 'Initial Reports & Analytics release.',
            routes: [
                'reports.index',
                'reports.run',
                'reports.export',
                'reports.saved.show',
                'reports.saved.export',
                'reports.saved.store',
                'reports.saved.update',
                'reports.saved.destroy',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
