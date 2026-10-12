<?php

declare(strict_types=1);

use App\Core\AppStore\Enums\ScheduledJobFrequency;
use App\Core\Modules\ModuleRegistry;
use App\Modules\EmailMarketing\Models\Campaign;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    $this->tenant = $this->emTenant('em-access');
    $this->list = $this->emList($this->tenant);
    $this->url = fn (string $path = '') => $this->emUrl($this->tenant, $path);
});

it('answers 404 for every Email Marketing page when the workspace is not entitled to the app', function () {
    $plain = $this->createTenant('em-plain');
    $this->actingAsOwner($plain);

    foreach (['/campaigns', '/campaigns/create', '/templates', '/templates/create', '/lists', '/subscribers', '/imports', '/sequences', '/sequences/create', '/reports', '/settings'] as $path) {
        $this->get($this->emUrl($plain, $path))->assertNotFound();
    }

    $this->post($this->emUrl($plain, '/campaigns'), ['name' => 'x'])->assertNotFound();
    $this->post($this->emUrl($plain, '/content/preview'), ['blocks' => []])->assertNotFound();
});

it('sends signed-out visitors to sign in', function () {
    foreach (['/campaigns', '/lists', '/reports'] as $path) {
        $this->get(($this->url)($path))->assertRedirect();
    }
});

it('denies each area to a member who lacks its permission', function () {
    [$viewer] = $this->emMember($this->tenant, ['email_marketing.campaigns.view']);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->actingAsMember($this->tenant, $viewer);

    $this->get(($this->url)('/campaigns'))->assertOk();
    $this->get(($this->url)("/campaigns/{$campaign->id}"))->assertOk();

    $this->get(($this->url)('/campaigns/create'))->assertForbidden();
    $this->post(($this->url)('/campaigns'), [])->assertForbidden();
    $this->get(($this->url)("/campaigns/{$campaign->id}/edit"))->assertForbidden();
    $this->put(($this->url)("/campaigns/{$campaign->id}"), [])->assertForbidden();
    $this->delete(($this->url)("/campaigns/{$campaign->id}"))->assertForbidden();
    $this->post(($this->url)("/campaigns/{$campaign->id}/schedule"))->assertForbidden();
    $this->post(($this->url)("/campaigns/{$campaign->id}/pause"))->assertForbidden();
    $this->post(($this->url)("/campaigns/{$campaign->id}/cancel"))->assertForbidden();
    $this->post(($this->url)("/campaigns/{$campaign->id}/test"), [])->assertForbidden();
    $this->get(($this->url)("/campaigns/{$campaign->id}/deliveries/export"))->assertForbidden();
    $this->get(($this->url)('/templates'))->assertForbidden();
    $this->post(($this->url)('/templates'), [])->assertForbidden();
    $this->get(($this->url)('/lists'))->assertForbidden();
    $this->post(($this->url)('/lists'), ['name' => 'x'])->assertForbidden();
    $this->get(($this->url)("/lists/{$this->list->id}/export"))->assertForbidden();
    $this->get(($this->url)('/subscribers'))->assertForbidden();
    $this->get(($this->url)('/subscribers/contacts?q=ab'))->assertForbidden();
    $this->post(($this->url)("/lists/{$this->list->id}/subscribers"), [])->assertForbidden();
    $this->get(($this->url)('/imports'))->assertForbidden();
    $this->post(($this->url)('/imports'), [])->assertForbidden();
    $this->get(($this->url)('/sequences'))->assertForbidden();
    $this->post(($this->url)('/sequences'), [])->assertForbidden();
    $this->get(($this->url)('/reports'))->assertForbidden();
    $this->get(($this->url)('/settings'))->assertForbidden();
    $this->put(($this->url)('/settings'), [])->assertForbidden();
});

it('narrows campaigns to the viewer\'s own scope in the list, by id and for actions', function () {
    [$user, $membership] = $this->emMember(
        $this->tenant,
        ['email_marketing.campaigns.view', 'email_marketing.campaigns.edit', 'email_marketing.campaigns.send'],
        ['email_marketing.campaigns.view' => 'own', 'email_marketing.campaigns.edit' => 'own'],
    );
    $mine = $this->emCampaign($this->tenant, $this->list, ['name' => 'Mine'], $membership);
    $theirs = $this->emCampaign($this->tenant, $this->list, ['name' => 'Theirs']);
    $this->actingAsMember($this->tenant, $user);

    $this->get(($this->url)('/campaigns'))->assertOk()
        ->assertInertia(fn ($page) => $page->has('campaigns.data', 1)->where('campaigns.data.0.name', 'Mine'));
    $this->get(($this->url)("/campaigns/{$mine->id}"))->assertOk();
    $this->get(($this->url)("/campaigns/{$theirs->id}"))->assertNotFound();
    $this->get(($this->url)("/campaigns/{$theirs->id}/edit"))->assertNotFound();
    $this->post(($this->url)("/campaigns/{$theirs->id}/schedule"))->assertNotFound();
    $this->post(($this->url)("/campaigns/{$theirs->id}/cancel"))->assertNotFound();
    $this->post(($this->url)("/campaigns/{$mine->id}/cancel"))->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(Campaign::query()->find($theirs->id)->status->value)->toBe('draft'));
});

it('lets a campaign author send nothing without the sensitive send permission', function () {
    [$author] = $this->emMember($this->tenant, ['email_marketing.campaigns.view', 'email_marketing.campaigns.create', 'email_marketing.campaigns.edit']);
    $campaign = $this->emCampaign($this->tenant, $this->list);
    $this->actingAsMember($this->tenant, $author);

    $this->get(($this->url)("/campaigns/{$campaign->id}"))->assertOk()
        ->assertInertia(fn ($page) => $page->where('campaign.abilities.schedule', false)->where('campaign.abilities.test', false));
    $this->post(($this->url)("/campaigns/{$campaign->id}/schedule"))->assertForbidden();
});

it('registers the app with its dependencies, permissions, limits and a minute runner', function () {
    $manifest = app(ModuleRegistry::class)->get('email_marketing');

    expect($manifest->dependencies)->toBe(['core', 'communication'])
        ->and(collect($manifest->permissions)->map->name->all())->toContain('email_marketing.campaigns.send', 'email_marketing.subscribers.import')
        ->and(collect($manifest->limits)->map->key->all())->toBe(['email_marketing.campaigns_per_month', 'email_marketing.subscribers'])
        ->and($manifest->scheduledJobs[0]->key)->toBe('email_marketing.run')
        ->and($manifest->scheduledJobs[0]->frequency)->toBe(ScheduledJobFrequency::EveryMinute);

    // Every named route in the manifest exists and every route of the module is declared.
    $declared = collect($manifest->routes);
    $actual = collect(app('router')->getRoutes()->getRoutesByName())->keys()->filter(fn ($name) => str_starts_with($name, 'email_marketing.'));

    expect($declared->diff($actual)->all())->toBe([])->and($actual->diff($declared)->all())->toBe([]);
});

it('never lets a sensitive permission be implied by view access', function () {
    foreach (['email_marketing.campaigns.send', 'email_marketing.subscribers.import', 'email_marketing.subscribers.export', 'email_marketing.settings.manage', 'email_marketing.sequences.activate', 'email_marketing.subscribers.manage'] as $name) {
        $definition = collect(app(ModuleRegistry::class)->get('email_marketing')->permissions)->firstWhere('name', $name);
        expect($definition->sensitive, $name)->toBeTrue();
    }

});
