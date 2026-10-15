<?php

declare(strict_types=1);

namespace App\Modules\Links;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Links\Automation\Triggers\LinkCreatedTrigger;
use App\Modules\Links\Models\Link;
use App\Modules\Links\Reporting\ReportProviders;

/** First-party links organiser: the websites, tools, email addresses and documents a team keeps coming back to. */
final class LinksServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'links',
            name: 'Links',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('links.links.view', 'links', scopable: true),
                new PermissionDefinition('links.links.manage', 'links', scopable: true),
            ],
            navigation: [
                new NavigationItem('links', 'links.index', 'link', 'links.links.view', 'apps', 11, 'links.*'),
            ],
            triggers: [
                LinkCreatedTrigger::class,
            ],
            morphMap: [
                'links_link' => Link::class,
            ],
            description: 'Keep the websites, tools, email addresses and documents your team uses in one organised place.',
            category: AppCategory::Productivity,
            icon: 'link',
            features: [
                'Websites, tools, email addresses and documents in one place',
                'Folders, pinned links and search',
                'Per-record ownership and scopes',
            ],
            offers: [
                new AppOffer('free', 'Free', AppBillingType::Free),
            ],
            releaseNotes: 'Initial Links release.',
            routes: [
                'links.index',
                'links.store',
                'links.update',
                'links.destroy',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
