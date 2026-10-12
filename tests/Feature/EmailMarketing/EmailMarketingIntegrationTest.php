<?php

declare(strict_types=1);

use App\Core\Access\Enums\AccessScope;
use App\Core\AppStore\AppCatalogSynchronizer;
use App\Core\AppStore\AppStoreManager;
use App\Core\AppStore\Jobs\RunScheduledAppJob;
use App\Core\AppStore\Models\CatalogApp;
use App\Core\Reporting\ReportContext;
use App\Modules\EmailMarketing\Actions\CampaignManager;
use App\Modules\EmailMarketing\Actions\EmailMarketingContactMergeReference;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Automation\Actions\AddToListAction;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Events\CampaignSent;
use App\Modules\EmailMarketing\Events\SubscriberSubscribed;
use App\Modules\EmailMarketing\Jobs\RunEmailMarketing;
use App\Modules\EmailMarketing\Models\AutomationActionReceipt;
use App\Modules\EmailMarketing\Models\Campaign;
use App\Modules\EmailMarketing\Models\Delivery;
use App\Modules\EmailMarketing\Models\Subscription;
use App\Modules\EmailMarketing\Reporting\CampaignPerformanceReport;
use App\Modules\EmailMarketing\Reporting\ListHealthReport;
use App\Modules\EmailMarketing\Widgets\EmailMarketingOverviewWidget;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->emTenant('em-int');
    $this->list = $this->emList($this->tenant);
});

it('queues one minute runner on the campaigns queue for a workspace that holds the app', function () {
    app(AppCatalogSynchronizer::class)->sync();
    $app = CatalogApp::query()->where('module_key', 'email_marketing')->with('currentVersion')->sole();

    expect($app->currentVersion?->scheduled_jobs)->toEqual([
        ['key' => 'email_marketing.run', 'job' => RunEmailMarketing::class, 'frequency' => 'every_minute'],
    ]);

    $this->travelTo(now()->startOfHour());
    $licensed = $this->createTenant('em-licensed', ['timezone' => 'UTC']);
    $communication = CatalogApp::query()->where('module_key', 'communication')->with('currentVersion')->sole();
    $this->inTenant($licensed, fn () => app(AppStoreManager::class)->installFree($communication, 'included'));
    $this->inTenant($licensed, fn () => app(AppStoreManager::class)->installFree($app, 'free'));
    Queue::fake();

    $this->artisan('apps:run-scheduled', ['--tenant' => $licensed->slug])->assertSuccessful();

    Queue::assertPushed(RunScheduledAppJob::class, fn (RunScheduledAppJob $job): bool => $job->definition === 'email_marketing.run' && $job->queue === 'campaigns');
});

it('sends nothing at worker time once the workspace is no longer entitled', function () {
    $stranger = $this->createTenant('em-withdrawn');
    $this->entitle($stranger, 'communication');
    $list = $this->emList($stranger);
    $contact = $this->emContact($stranger);
    $this->inTenant($stranger, fn () => app(SubscriberManager::class)->add(
        $list, $contact->id, ['consent_method' => 'website_form', 'consent_evidence' => 'Form on the website'], SubscriptionSource::Manual,
    ));
    $this->optIn($stranger, $contact);
    $campaign = $this->inTenant($stranger, fn () => Campaign::query()->create([
        'name' => 'C', 'subject' => 'S', 'blocks' => [['type' => 'text', 'text' => 'Hi']], 'list_id' => $list->id,
        'status' => 'scheduled', 'scheduled_for' => now()->subMinute(),
    ]));

    $job = new RunScheduledAppJob('email_marketing', 'email_marketing.run', new RunEmailMarketing);
    $this->inTenant($stranger, fn () => app()->call([$job, 'handle']));

    $this->inTenant($stranger, fn () => expect(Delivery::query()->count())->toBe(0)->and($campaign->fresh()->status->value)->toBe('scheduled'));

    // The entitled workspace goes through the same runner.
    $entitled = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $entitled);
    $own = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($own, null));
    $job = new RunScheduledAppJob('email_marketing', 'email_marketing.run', new RunEmailMarketing);
    $this->inTenant($this->tenant, fn () => app()->call([$job, 'handle']));

    $this->inTenant($this->tenant, fn () => expect(Delivery::query()->sole()->status->value)->toBe('sent'));
});

it('adds a contact to a list from a workflow as pending, once per step, and says why when it cannot', function () {
    $contact = $this->emContact($this->tenant);
    $action = fn (array $input, string $key) => $this->inTenant($this->tenant, fn () => app(AddToListAction::class)->execute($input, $key));

    $first = $action(['list_id' => $this->list->id, 'contact_id' => $contact->id], 'run-1:step-1');
    $again = $action(['list_id' => $this->list->id, 'contact_id' => $contact->id], 'run-1:step-1');

    expect($first['added'])->toBeTrue()->and($first['status'])->toBe('pending')->and($again)->toBe($first);

    $this->inTenant($this->tenant, fn () => expect(Subscription::query()->count())->toBe(1)
        ->and(Subscription::query()->sole()->source)->toBe('automation')
        ->and(Subscription::query()->sole()->consent_evidence)->toBeNull()
        ->and(AutomationActionReceipt::query()->count())->toBe(1));

    expect($action(['list_id' => str_repeat('0', 26), 'contact_id' => $contact->id], 'run-2')['reason'])->toBe('list_not_found')
        ->and($action(['list_id' => $this->list->id, 'contact_id' => str_repeat('0', 26)], 'run-3')['reason'])->toBe('contact_not_found');
});

it('announces a finished campaign and a new subscriber with ids and counts only', function () {
    Event::fake([CampaignSent::class, SubscriberSubscribed::class]);

    $contact = $this->emContact($this->tenant, ['email' => 'private@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $contact);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);

    Event::assertDispatched(SubscriberSubscribed::class, fn (SubscriberSubscribed $event): bool => $event->payload()['contact_id'] === $contact->id
        && ! str_contains(json_encode($event->payload()), 'private@example.test'));
    Event::assertDispatched(CampaignSent::class, fn (CampaignSent $event): bool => $event->payload()['campaign_id'] === $campaign->id
        && $event->payload()['sent'] === 1 && $event->payload()['audience'] === 1);
});

it('reports what was sent, blocked and clicked, and never invents an open rate', function () {
    $sent = $this->emContact($this->tenant);
    $blocked = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $sent);
    $this->emSubscribe($this->tenant, $this->list, $blocked, platformConsent: false);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, null));
    $this->emRun($this->tenant);
    $this->inTenant($this->tenant, fn () => Delivery::query()->where('contact_id', $sent->id)->update(['clicked_at' => now(), 'click_count' => 3]));

    $owner = $this->ownerOf($this->tenant);
    $context = new ReportContext($owner, AccessScope::All, '2026-01-01', '2036-01-01', CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2036-01-01'), 'UTC', [], 100);

    $rows = $this->inTenant($this->tenant, fn () => app(CampaignPerformanceReport::class)->run($context)->rows);
    $health = $this->inTenant($this->tenant, fn () => app(ListHealthReport::class)->run($context)->rows);
    $columns = collect(app(CampaignPerformanceReport::class)->definition()->columns)->map->key->all();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['planned'])->toBe(2)
        ->and($rows[0]['sent'])->toBe(1)
        ->and($rows[0]['blocked'])->toBe(1)
        ->and($rows[0]['clickers'])->toBe(1)
        ->and($rows[0]['click_rate'])->toBe(10000)
        ->and($columns)->not->toContain('open_rate', 'opens')
        ->and($health[0]['subscribed'])->toBe(2);

    $this->actingAsOwner($this->tenant);
    $this->get($this->emUrl($this->tenant, '/reports'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('report.deliveries.sent', 1)->where('report.deliveries.blocked', 1)
            ->where('report.reasons.0.reason', 'no_consent'));
});

it('feeds the dashboard widget with the viewer\'s scope', function () {
    $this->emSubscribe($this->tenant, $this->list, $this->emContact($this->tenant));
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->inTenant($this->tenant, fn () => app(CampaignManager::class)->schedule($campaign, CarbonImmutable::now()->addDay()));

    $data = $this->inTenant($this->tenant, fn () => app(EmailMarketingOverviewWidget::class)($this->ownerOf($this->tenant)));

    expect($data['scheduled'])->toBe(1)->and($data['subscribers'])->toBe(1)->and($data['last'])->toBeNull();
});

it('keeps the stricter membership state when two contacts are merged', function () {
    $canonical = $this->emContact($this->tenant);
    $duplicate = $this->emContact($this->tenant);
    $this->emSubscribe($this->tenant, $this->list, $canonical, platformConsent: false);
    $gone = $this->emSubscribe($this->tenant, $this->list, $duplicate, platformConsent: false);
    $other = $this->emList($this->tenant, 'Other');
    $this->emSubscribe($this->tenant, $other, $duplicate, platformConsent: false);
    $this->inTenant($this->tenant, fn () => $gone->forceFill(['status' => SubscriptionStatus::Unsubscribed])->save());

    $counts = $this->inTenant($this->tenant, fn () => app(EmailMarketingContactMergeReference::class)->merge($this->tenant->id, $canonical->id, $duplicate->id));

    expect($counts['email_marketing_subscriptions'])->toBe(2);

    $this->inTenant($this->tenant, function () use ($canonical): void {
        $rows = Subscription::query()->orderBy('created_at')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('contact_id')->unique()->all())->toBe([$canonical->id])
            ->and($rows->firstWhere('list_id', $this->list->id)->status)->toBe(SubscriptionStatus::Unsubscribed);
    });
});
