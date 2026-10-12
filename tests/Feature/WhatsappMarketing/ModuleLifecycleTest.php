<?php

declare(strict_types=1);

use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Modules\ModuleRegistry;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Jobs\RunCampaigns;
use App\Modules\WhatsappMarketing\Jobs\RunMaintenance;
use App\Modules\WhatsappMarketing\Models\Campaign;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithWhatsapp;

uses(InteractsWithWhatsapp::class);

it('declares its permissions with the module prefix, the scopable ones and the right dependencies', function () {
    $manifest = app(ModuleRegistry::class)->get('whatsapp_marketing');
    $names = array_map(fn ($permission): string => $permission->name, $manifest->permissions);
    $scopable = array_values(array_map(fn ($p): string => $p->name, array_filter($manifest->permissions, fn ($p): bool => $p->scopable)));

    expect($manifest->dependencies)->toBe(['core', 'communication'])
        ->and($manifest->core)->toBeFalse()
        ->and($names)->toHaveCount(16)
        ->and(collect($names)->every(fn (string $name): bool => str_starts_with($name, 'whatsapp_marketing.')))->toBeTrue()
        ->and($scopable)->toBe([
            'whatsapp_marketing.conversations.view', 'whatsapp_marketing.conversations.reply',
            'whatsapp_marketing.conversations.manage', 'whatsapp_marketing.conversations.assign',
            'whatsapp_marketing.campaigns.view', 'whatsapp_marketing.campaigns.manage',
        ])
        ->and(array_map(fn ($limit): string => $limit->key, $manifest->limits))
        ->toBe(['whatsapp_marketing.conversations_per_month', 'whatsapp_marketing.agents']);
});

it('registers a morph alias for every model and a route for every navigation item', function () {
    $manifest = app(ModuleRegistry::class)->get('whatsapp_marketing');

    foreach ($manifest->morphMap as $alias => $class) {
        expect(Relation::getMorphedModel($alias))->toBe($class)->and($alias)->toStartWith('whatsapp_');
    }

    foreach ($manifest->navigation as $item) {
        expect(Route::has($item->route))->toBeTrue("[{$item->route}] is not a route");
    }

    foreach ($manifest->routes as $name) {
        expect(Route::has($name))->toBeTrue("[{$name}] is listed but does not exist");
    }

    expect($manifest->widgets[0]->component)->toBe('WhatsappOverviewWidget');
});

it('offers its catalogue entry in the WhatsApp marketing category with one free offer', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'whatsapp_marketing')->with('currentVersion')->sole();

    expect($app->category->value)->toBe('whatsapp_marketing')
        ->and($app->currentVersion?->dependencies)->toContain('communication')
        ->and($app->currentVersion?->scheduled_jobs)->toEqual([
            ['key' => 'whatsapp_marketing.campaigns', 'job' => RunCampaigns::class, 'frequency' => ScheduledJobFrequency::EveryMinute->value],
            ['key' => 'whatsapp_marketing.maintenance', 'job' => RunMaintenance::class, 'frequency' => ScheduledJobFrequency::Daily->value],
        ]);
});

it('queues its schedules only for a workspace that holds the app and its dependency', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $communication = CatalogApp::query()->where('module_key', 'communication')->sole();
    $app = CatalogApp::query()->where('module_key', 'whatsapp_marketing')->sole();

    $this->travelTo(now()->startOfHour());
    $licensed = $this->createTenant('wa-licensed', ['timezone' => 'UTC']);
    $this->inTenant($licensed, function () use ($communication, $app): void {
        app(AppStoreManager::class)->installFree($communication, 'included');
        app(AppStoreManager::class)->installFree($app, 'included');
    });
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'whatsapp_marketing.campaigns');
});

it('starts nothing at worker time once the workspace is no longer entitled', function () {
    $stranger = $this->createTenant('wa-withdrawn', ['timezone' => 'UTC']);
    $campaign = $this->inTenant($stranger, fn () => Campaign::query()->create([
        'name' => 'Due', 'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'template_name' => 't',
        'template_language' => 'en_US', 'template_category' => 'marketing', 'template_body' => 'x', 'variable_map' => [], 'total_recipients' => 0,
    ]));

    // The job is dispatched for a workspace that no longer holds the app: the runner re-checks and does nothing.
    $job = new RunScheduledAppJob('whatsapp_marketing', 'whatsapp_marketing.campaigns', new RunCampaigns);
    $this->inTenant($stranger, fn () => app()->call([$job, 'handle']));

    $this->inTenant($stranger, fn () => expect($campaign->fresh()->status)->toBe(CampaignStatus::Scheduled));

    // The same job for an entitled workspace does start it (channel unavailable then stops it, but it left `scheduled`).
    $entitled = $this->waTenant('wa-entitled');
    $due = $this->inTenant($entitled, fn () => Campaign::query()->create([
        'name' => 'Due', 'status' => CampaignStatus::Scheduled, 'scheduled_at' => now()->subMinute(), 'template_name' => 't',
        'template_language' => 'en_US', 'template_category' => 'marketing', 'template_body' => 'x', 'variable_map' => [], 'total_recipients' => 0,
    ]));
    $job = new RunScheduledAppJob('whatsapp_marketing', 'whatsapp_marketing.campaigns', new RunCampaigns);
    $this->inTenant($entitled, fn () => app()->call([$job, 'handle']));

    $this->inTenant($entitled, fn () => expect($due->fresh()->status)->not->toBe(CampaignStatus::Scheduled));
});

it('cannot be installed without the communication app', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'whatsapp_marketing')->sole();
    $tenant = $this->createTenant('wa-no-comm');

    expect(fn () => $this->inTenant($tenant, fn () => app(AppStoreManager::class)->installFree($app, 'included')))
        ->toThrow(ValidationException::class);
});
