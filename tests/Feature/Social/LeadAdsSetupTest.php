<?php

declare(strict_types=1);

use App\Core\Access\AccessChecker;
use App\Core\Access\Enums\AccessScope;
use App\Core\Audit\Models\AuditLog;
use App\Core\Reporting\ReportContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\LeadAds\LeadAdsFigures;
use App\Modules\Social\LeadAds\LeadStatus;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdLog;
use App\Modules\Social\LeadAds\Models\LeadAdsPage;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\LeadAds\Models\LeadPageClaim;
use App\Modules\Social\LeadAds\Reporting\LeadAdsReport;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCrmContracts;
use Tests\Support\InteractsWithLeadAds;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class, InteractsWithLeadAds::class, InteractsWithCrmContracts::class);

beforeEach(function () {
    $this->leadAdsConfig();
    $this->tenant = $this->socialTenant('lead-setup');
    $this->fakePlatform();
    $this->intake = $this->fakeLeadIntake();
    $this->url = fn (string $path = '') => $this->socialUrl($this->tenant, '/lead-ads'.$path);
});

it('switches lead capture on: checks permissions, claims the Page, subscribes to leadgen and lists the forms', function () {
    $account = $this->plainFbAccount($this->tenant);
    $this->fakeGraphSetup(['leads_retrieval', 'pages_manage_ads', 'pages_show_list', 'pages_manage_metadata']);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/'.$account->id.'/enable'))->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $page = LeadAdsPage::query()->sole();
        $form = LeadForm::query()->sole();

        expect($page->state)->toBe('subscribed')->and($page->page_id)->toBe('2000')->and($page->missing_scopes)->toBe([])
            ->and(LeadPageClaim::query()->where('page_id', '2000')->where('lead_page_id', $page->id)->exists())->toBeTrue()
            ->and($form->external_id)->toBe('777')->and($form->is_enabled)->toBeFalse()->and($form->questions)->toHaveCount(3)
            ->and(AuditLog::query()->where('event', 'social.leadads.enabled')->exists())->toBeTrue();
    });

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2000/subscribed_apps') && $request->method() === 'POST'
        && $request['subscribed_fields'] === 'leadgen' && $request->hasHeader('Authorization', 'Bearer tok-2000'));
});

it('explains missing permissions and does not subscribe or claim', function () {
    $account = $this->plainFbAccount($this->tenant);
    $this->fakeGraphSetup(['pages_show_list']);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/'.$account->id.'/enable'))->assertSessionHasErrors('page');

    $this->inTenant($this->tenant, function (): void {
        $page = LeadAdsPage::query()->sole();

        expect($page->state)->toBe('failed')->and($page->missing_scopes)->toContain('leads_retrieval', 'pages_manage_ads')
            ->and(LeadPageClaim::query()->count())->toBe(0);
    });
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));

    $this->get(($this->url)())->assertInertia(fn (AssertableInertia $page) => $page
        ->where('pages.0.state', 'failed')
        ->where('pages.0.missingScopes', ['leads_retrieval', 'pages_manage_ads', 'pages_manage_metadata']));
});

it('refuses to switch on when the operator has not enabled or configured lead ads', function () {
    $account = $this->plainFbAccount($this->tenant);
    Http::fake();
    $this->actingAsOwner($this->tenant);

    $this->leadAdsConfig(false);
    $this->post(($this->url)('/accounts/'.$account->id.'/enable'))->assertSessionHasErrors('page');

    $this->leadAdsConfig();
    config(['social.lead_ads.webhook_verify_token' => null]);
    $this->post(($this->url)('/accounts/'.$account->id.'/enable'))->assertSessionHasErrors('page');

    $this->get(($this->url)())->assertInertia(fn (AssertableInertia $page) => $page->where('availability.problem', 'webhook_not_configured'));
    Http::assertNothingSent();
});

it('lets one workspace hold a Page: a second workspace is refused', function () {
    $mine = $this->fbLeadPage($this->tenant, '3000');
    $other = $this->socialTenant('lead-setup-2');
    $account = $this->plainFbAccount($other, '3000');
    $this->fakeGraphSetup(['leads_retrieval', 'pages_manage_ads', 'pages_show_list', 'pages_manage_metadata']);
    $this->actingAsOwner($other);

    $this->post($this->socialUrl($other, '/lead-ads/accounts/'.$account->id.'/enable'))
        ->assertSessionHasErrors('page');

    $this->inTenant($other, fn () => expect(LeadAdsPage::query()->sole()->state)->not->toBe('subscribed'));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'subscribed_apps'));
    expect(LeadPageClaim::query()->where('page_id', '3000')->count())->toBe(1);
});

it('removes the claim and unsubscribes when switched off, and keeps received leads', function () {
    $set = $this->fbLeadPage($this->tenant, '4000');
    $this->inTenant($this->tenant, fn () => LeadAdLead::query()->create([
        'account_id' => $set['account']->id, 'leadgen_id' => 'K1', 'page_id' => '4000', 'status' => LeadStatus::Captured, 'received_at' => now(),
    ]));
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/'.$set['account']->id.'/disable'))->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(LeadAdsPage::query()->sole()->state)->toBe('off')->and(LeadAdLead::query()->count())->toBe(1));
    expect(LeadPageClaim::query()->where('page_id', '4000')->exists())->toBeFalse();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/4000/subscribed_apps'));

    // A delivery for the released Page changes nothing.
    $this->leadWebhook($this->tenant, $this->leadgenPayload('K2', '4000'))->assertOk();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(1));
});

it('switches a form and saves a validated mapping, then previews it', function () {
    $set = $this->fbLeadPage($this->tenant);
    $this->actingAsOwner($this->tenant);
    $form = $set['form'];

    $this->post(($this->url)('/forms/'.$form->id.'/switch'), ['enabled' => false])->assertRedirect();
    $this->inTenant($this->tenant, fn () => expect(LeadForm::query()->sole()->is_enabled)->toBeFalse());

    $this->put(($this->url)('/forms/'.$form->id.'/mapping'), ['mapping' => ['nope' => ['target' => 'email']]])->assertSessionHasErrors('mapping');
    $this->put(($this->url)('/forms/'.$form->id.'/mapping'), ['mapping' => ['city' => ['target' => 'weird']]])->assertSessionHasErrors();
    $this->put(($this->url)('/forms/'.$form->id.'/mapping'), ['mapping' => [
        'full_name' => ['target' => 'consent'], 'city' => ['target' => 'consent'],
    ]])->assertSessionHasErrors('mapping');
    $this->put(($this->url)('/forms/'.$form->id.'/mapping'), ['mapping' => ['city' => ['target' => 'answer', 'answer_key' => '<b>x</b>']]])->assertSessionHasErrors('mapping');

    $this->put(($this->url)('/forms/'.$form->id.'/mapping'), ['mapping' => [
        'city' => ['target' => 'answer', 'answer_key' => 'Home city'],
        'phone_number' => ['target' => 'ignore'],
    ]])->assertRedirect();

    $saved = $this->inTenant($this->tenant, fn () => LeadForm::query()->sole()->mapping);
    expect($saved['city'])->toBe(['target' => 'answer', 'answer_key' => 'Home city']);

    $this->postJson(($this->url)('/forms/'.$form->id.'/preview'), ['mapping' => ['phone_number' => ['target' => 'phone'], 'email' => ['target' => 'ignore']]])
        ->assertOk()
        ->assertJsonPath('preview.phone', '+8801712345678')
        ->assertJsonPath('preview.email', null)
        ->assertJsonPath('preview.answers.Home city', null);
});

it('shows the setup page with counts, history, forms and the CRM-not-available state', function () {
    $this->intake = $this->fakeLeadIntake(available: false);
    $set = $this->fbLeadPage($this->tenant);
    $this->fakeGraphLead();
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    $this->actingAsOwner($this->tenant);

    $lead = $this->inTenant($this->tenant, fn () => LeadAdLead::query()->sole());

    $this->get(($this->url)().'?lead='.$lead->id)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Modules/Social/Pages/LeadAds/Index')
        ->where('availability.crmAvailable', false)
        ->where('availability.problem', null)
        ->has('pages', 1)
        ->where('pages.0.state', 'subscribed')
        ->where('pages.0.counts.received', 1)
        ->where('pages.0.counts.waiting', 1)
        ->has('pages.0.history', 14)
        ->has('pages.0.forms', 1)
        ->where('pages.0.forms.0.mapping.email.target', 'email')
        ->where('pages.0.forms.0.preview.email', 'sample@example.com')
        ->has('leads.data', 1)
        ->where('leads.data.0.status', 'waiting_for_crm')
        ->where('selected.lead.id', $lead->id)
        ->has('selected.log', 3)
        ->where('canManage', true));

    // Neither tokens nor answers reach the browser.
    $html = json_encode($this->get(($this->url)())->viewData('page'));
    expect($html)->not->toContain(self::PAGE_TOKEN)->not->toContain('Rahim')->not->toContain('rahim@example.com');
});

it('enforces view and manage permissions and the entitlement', function () {
    $set = $this->fbLeadPage($this->tenant);
    $lead = $this->inTenant($this->tenant, fn () => LeadAdLead::query()->create([
        'account_id' => $set['account']->id, 'leadgen_id' => 'Z1', 'page_id' => '1000', 'status' => LeadStatus::Failed, 'received_at' => now(),
    ]));

    [$viewer] = $this->socialMember($this->tenant, ['social.leadads.view']);
    [$nobody] = $this->socialMember($this->tenant, ['social.posts.view']);

    $this->actingAsMember($this->tenant, $nobody);
    $this->get(($this->url)())->assertForbidden();

    $this->startNewBrowserSession();
    $this->actingAsMember($this->tenant, $viewer);
    $this->get(($this->url)())->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false));
    $this->post(($this->url)('/accounts/'.$set['account']->id.'/enable'))->assertForbidden();
    $this->post(($this->url)('/accounts/'.$set['account']->id.'/backfill'))->assertForbidden();
    $this->post(($this->url)('/forms/'.$set['form']->id.'/switch'), ['enabled' => false])->assertForbidden();
    $this->put(($this->url)('/forms/'.$set['form']->id.'/mapping'), ['mapping' => []])->assertForbidden();
    $this->post(($this->url)('/leads/'.$lead->id.'/retry'))->assertForbidden();
    $this->inTenant($this->tenant, fn () => expect(LeadForm::query()->sole()->is_enabled)->toBeTrue());

    // A workspace without the Social app: not found.
    $bare = $this->createTenant('no-social-leads');
    $this->actingAsOwner($bare);
    $this->get($this->tenantUrl($bare, '/social/lead-ads'))->assertNotFound();
    $this->leadWebhook($bare, $this->leadgenPayload())->assertNotFound();
});

it('does not reach another workspace through a form, account or lead id', function () {
    $set = $this->fbLeadPage($this->tenant);
    $lead = $this->inTenant($this->tenant, fn () => LeadAdLead::query()->create([
        'account_id' => $set['account']->id, 'leadgen_id' => 'Z2', 'page_id' => '1000', 'status' => LeadStatus::Failed, 'received_at' => now(),
    ]));
    $other = $this->socialTenant('lead-setup-3');
    $this->actingAsOwner($other);
    Http::fake();

    foreach ([
        ['POST', '/social/lead-ads/accounts/'.$set['account']->id.'/enable', []],
        ['POST', '/social/lead-ads/accounts/'.$set['account']->id.'/disable', []],
        ['POST', '/social/lead-ads/accounts/'.$set['account']->id.'/forms/refresh', []],
        ['POST', '/social/lead-ads/accounts/'.$set['account']->id.'/backfill', []],
        ['POST', '/social/lead-ads/forms/'.$set['form']->id.'/switch', ['enabled' => false]],
        ['PUT', '/social/lead-ads/forms/'.$set['form']->id.'/mapping', ['mapping' => ['city' => ['target' => 'answer']]]],
        ['POST', '/social/lead-ads/forms/'.$set['form']->id.'/preview', ['mapping' => ['city' => ['target' => 'answer']]]],
        ['POST', '/social/lead-ads/leads/'.$lead->id.'/retry', []],
    ] as [$method, $path, $data]) {
        $this->call($method, $this->tenantUrl($other, $path), $data)->assertNotFound();
    }

    // The other workspace's page lists nothing of ours.
    $this->get($this->tenantUrl($other, '/social/lead-ads'))->assertInertia(fn (AssertableInertia $page) => $page->has('pages', 0)->has('leads.data', 0));
    Http::assertNothingSent();
});

it('isolates every lead-ad model by workspace', function () {
    $this->assertTenantIsolated(LeadAdsPage::class, function () {
        $account = $this->socialAccount(app(TenantContext::class)->require(), ['platform' => 'facebook']);

        return LeadAdsPage::query()->create(['account_id' => $account->id, 'page_id' => $account->external_id, 'state' => 'off']);
    });
    $this->assertTenantIsolated(LeadForm::class, function () {
        $account = $this->socialAccount(app(TenantContext::class)->require(), ['platform' => 'facebook']);

        return LeadForm::query()->create(['account_id' => $account->id, 'external_id' => 'F9', 'name' => 'F', 'questions' => []]);
    });
    $this->assertTenantIsolated(LeadAdLead::class, function () {
        $account = $this->socialAccount(app(TenantContext::class)->require(), ['platform' => 'facebook']);

        return LeadAdLead::query()->create(['account_id' => $account->id, 'leadgen_id' => 'LL'.bin2hex(random_bytes(3)), 'page_id' => '1', 'received_at' => now()]);
    });
});

it('fetches recent leads form by form inside the window and the lead budget, skipping known leads', function () {
    $set = $this->fbLeadPage($this->tenant);
    config(['social.lead_ads.backfill_max_leads' => 3, 'social.lead_ads.backfill_max_days' => 14]);
    $this->inTenant($this->tenant, fn () => LeadAdLead::query()->create([
        'account_id' => $set['account']->id, 'leadgen_id' => 'B1', 'page_id' => '1000', 'status' => LeadStatus::Captured, 'received_at' => now(),
    ]));
    Http::fake(['graph.facebook.com/*/F1/leads*' => Http::response(['data' => array_map(
        fn (string $id): array => $this->leadNode($id, [['name' => 'email', 'values' => [strtolower($id).'@example.com']]]),
        ['B1', 'B2', 'B3', 'B4', 'B5'],
    )])]);
    $this->actingAsOwner($this->tenant);

    // A huge window is clamped to the operator's maximum.
    $this->post(($this->url)('/accounts/'.$set['account']->id.'/backfill'), ['days' => 365])->assertRedirect();

    $this->inTenant($this->tenant, function (): void {
        $ids = LeadAdLead::query()->orderBy('leadgen_id')->pluck('leadgen_id')->all();

        // 3 listed within the budget: B1 was already known, so B2 and B3 are new; B4 and B5 were cut off.
        expect($ids)->toBe(['B1', 'B2', 'B3'])
            ->and(LeadAdLead::query()->where('leadgen_id', 'B2')->value('origin'))->toBe('backfill')
            ->and(LeadAdLead::query()->where('leadgen_id', 'B2')->value('status'))->toBe(LeadStatus::Captured)
            ->and(LeadAdsPage::query()->sole()->last_backfill_at)->not->toBeNull();
    });

    Http::assertSent(function ($request): bool {
        if (! str_contains($request->url(), '/F1/leads')) {
            return false;
        }

        $filter = json_decode((string) $request['filtering'], true);
        $since = $filter[0]['value'];

        return $filter[0]['field'] === 'time_created' && $since >= now()->subDays(14)->subMinute()->getTimestamp() && $since <= now()->subDays(14)->addMinute()->getTimestamp();
    });
    // The listed answers are used: no per-lead fetch.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/B2'));
    expect($this->intake->captures)->toHaveCount(2);
});

it('refuses to fetch recent leads before capture and a form are on', function () {
    $account = $this->plainFbAccount($this->tenant);
    Http::fake();
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/'.$account->id.'/backfill'))->assertRedirect();
    Http::assertNothingSent();

    $set = $this->fbLeadPage($this->tenant, '5000', formEnabled: false);
    $this->post(($this->url)('/accounts/'.$set['account']->id.'/backfill'))->assertRedirect();
    Http::assertNothingSent();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(0));
});

it('stops a backfill on an expired token and asks for reconnection', function () {
    $set = $this->fbLeadPage($this->tenant);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'expired']], 401)]);
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/accounts/'.$set['account']->id.'/backfill'))->assertRedirect();

    $this->inTenant($this->tenant, fn () => expect(SocialAccount::query()->sole()->status)->toBe(AccountStatus::NeedsReauthorization));
});

it('retries a lead only for the states that can still change', function () {
    $set = $this->fbLeadPage($this->tenant);
    $done = $this->inTenant($this->tenant, fn () => LeadAdLead::query()->create([
        'account_id' => $set['account']->id, 'leadgen_id' => 'D1', 'page_id' => '1000', 'status' => LeadStatus::Captured, 'received_at' => now(),
        'crm_lead_id' => 'x', 'crm_contact_id' => 'y',
    ]));
    Http::fake();
    $this->actingAsOwner($this->tenant);

    $this->post(($this->url)('/leads/'.$done->id.'/retry'))->assertRedirect();
    Http::assertNothingSent();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->whereKey($done->id)->value('status'))->toBe(LeadStatus::Captured)
        ->and(LeadAdLog::query()->where('lead_id', $done->id)->count())->toBe(0));
});

it('reports leads by day, Page and form, and counts them for the dashboard widget', function () {
    $set = $this->fbLeadPage($this->tenant);
    $this->inTenant($this->tenant, function () use ($set): void {
        foreach ([['R1', LeadStatus::Captured], ['R2', LeadStatus::Failed], ['R3', LeadStatus::WaitingForCrm]] as [$id, $status]) {
            LeadAdLead::query()->create([
                'account_id' => $set['account']->id, 'form_id' => $set['form']->id, 'external_form_id' => 'F1', 'leadgen_id' => $id,
                'page_id' => '1000', 'status' => $status, 'received_at' => now(),
            ]);
        }
    });
    $owner = $this->ownerOf($this->tenant);

    $this->inTenant($this->tenant, function () use ($owner): void {
        $definition = (new LeadAdsReport)->definition();
        expect($definition->key)->toBe('social.lead_ads')->and($definition->permission)->toBe('social.leadads.view');

        $result = (new LeadAdsReport)->run(new ReportContext(
            $owner, AccessScope::All, now()->format('Y-m-d'), now()->format('Y-m-d'),
            now()->startOfDay()->utc()->toImmutable(), now()->endOfDay()->utc()->toImmutable(), 'UTC', [], 100,
        ));

        expect($result->rows)->toHaveCount(1)
            ->and($result->rows[0]['page'])->toBe('Brand Page 1000')->and($result->rows[0]['form'])->toBe('Spring offer')
            ->and($result->rows[0]['received'])->toBe(3)->and($result->rows[0]['captured'])->toBe(1)
            ->and($result->rows[0]['failed'])->toBe(1)->and($result->rows[0]['waiting'])->toBe(1);

        $figures = LeadAdsFigures::forUser(app(AccessChecker::class), $owner);
        expect($figures)->toMatchArray(['leadAdsEnabled' => true, 'leadAdsReceived' => 3, 'leadAdsProcessed' => 1, 'leadAdsFailed' => 1, 'leadAdsWaiting' => 1]);
    });

    [$noLeads] = $this->socialMember($this->tenant, ['social.posts.view']);
    $this->inTenant($this->tenant, fn () => expect(LeadAdsFigures::forUser(app(AccessChecker::class), $noLeads))->toBe([]));
});
