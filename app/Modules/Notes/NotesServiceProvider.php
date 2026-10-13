<?php

declare(strict_types=1);

namespace App\Modules\Notes;

use App\Core\AppStore\Enums\AppBillingType;
use App\Core\AppStore\Enums\AppCategory;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Modules\AppOffer;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleServiceProvider;
use App\Core\Modules\NavigationItem;
use App\Core\Modules\PermissionDefinition;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Notes\Automation\Triggers\NoteCreatedTrigger;
use App\Modules\Notes\CustomerRecords\NotesSection;
use App\Modules\Notes\Models\Note;
use App\Modules\Notes\Reporting\ReportProviders;

/** First-party notes module: short notes linked to shared contacts and companies. */
final class NotesServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        parent::register();
        $this->app->tag(ReportProviders::ALL, ReportProvider::TAG);
        $this->app->tag([NotesSection::class], CustomerRecordProvider::TAG);
    }

    protected function describe(): ModuleManifest
    {
        return new ModuleManifest(
            key: 'notes',
            name: 'Notes',
            version: '1.0.0',
            dependencies: ['core'],
            permissions: [
                new PermissionDefinition('notes.notes.view', 'notes', scopable: true),
                new PermissionDefinition('notes.notes.manage', 'notes', scopable: true),
            ],
            navigation: [
                new NavigationItem('notes', 'notes.index', 'file-text', 'notes.notes.view', 'apps', 10, 'notes.*'),
            ],
            triggers: [
                NoteCreatedTrigger::class,
            ],
            morphMap: [
                'notes_note' => Note::class,
            ],
            description: 'Capture short notes and pin the important ones, linked to your shared contacts and companies.',
            category: AppCategory::Productivity,
            icon: 'file-text',
            features: [
                'Pinned and searchable notes',
                'Link notes to contacts and companies',
                'Per-record ownership and scopes',
            ],
            offers: [
                new AppOffer('free', 'Free', AppBillingType::Free),
            ],
            releaseNotes: 'Initial notes release.',
            routes: [
                'notes.index',
                'notes.store',
                'notes.update',
                'notes.destroy',
            ],
        );
    }

    protected function bootModule(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadTenantRoutes(__DIR__.'/routes.php');
    }
}
