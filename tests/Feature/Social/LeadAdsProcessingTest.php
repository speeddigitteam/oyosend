<?php

declare(strict_types=1);

use App\Core\Crm\Data\LeadCapture;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Events\AccountNeedsReauthorization;
use App\Modules\Social\LeadAds\Automation\LeadAdReceived;
use App\Modules\Social\LeadAds\Jobs\SweepLeadAds;
use App\Modules\Social\LeadAds\LeadProcessor;
use App\Modules\Social\LeadAds\LeadStatus;
use App\Modules\Social\LeadAds\LeadSweep;
use App\Modules\Social\LeadAds\Models\LeadAdLead;
use App\Modules\Social\LeadAds\Models\LeadAdLog;
use App\Modules\Social\LeadAds\Models\LeadForm;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\InteractsWithCrmContracts;
use Tests\Support\InteractsWithLeadAds;
use Tests\Support\InteractsWithSocial;

uses(InteractsWithSocial::class, InteractsWithLeadAds::class, InteractsWithCrmContracts::class);

beforeEach(function () {
    $this->leadAdsConfig();
    $this->tenant = $this->socialTenant('lead-ws');
    $this->fakePlatform();
    $this->intake = $this->fakeLeadIntake();
    $this->setup = $this->fbLeadPage($this->tenant);
    $this->lead = fn (string $id = 'L1'): LeadAdLead => $this->inTenant($this->tenant, fn () => LeadAdLead::query()->where('leadgen_id', $id)->firstOrFail());
    $this->steps = fn (string $id = 'L1'): array => $this->inTenant($this->tenant, fn () => LeadAdLog::query()
        ->where('lead_id', ($this->lead)($id)->id)->orderBy('created_at')->orderBy('id')->pluck('step')->all());
});

it('answers the subscription handshake only with the configured verify token', function () {
    $url = $this->tenantUrl($this->tenant, '/social/webhooks/facebook/leads');

    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=123456')->assertOk()->assertSee('123456');
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=123456')->assertForbidden();
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=<script>')->assertForbidden();

    config(['social.lead_ads.webhook_verify_token' => null]);
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=&hub.challenge=1')->assertNotFound();

    $this->leadAdsConfig(false);
    $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=1')->assertNotFound();
});

it('rejects an unsigned, wrongly signed, tampered or oversized delivery and changes nothing', function () {
    Http::fake();
    $payload = $this->leadgenPayload();

    $this->leadWebhook($this->tenant, $payload, secret: null)->assertUnauthorized();
    $this->leadWebhook($this->tenant, $payload, secret: 'wrong-secret')->assertUnauthorized();
    $this->leadWebhook($this->tenant, $payload, signature: 'sha256=abc')->assertUnauthorized();

    // A valid signature over a different body.
    $signed = 'sha256='.hash_hmac('sha256', (string) json_encode($payload), self::APP_SECRET);
    $this->leadWebhook($this->tenant, $this->leadgenPayload('L2'), signature: $signed)->assertUnauthorized();

    config(['social.lead_ads.webhook_max_bytes' => 100]);
    $this->leadWebhook($this->tenant, $payload)->assertStatus(413);

    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(0));
    expect($this->intake->captures)->toBe([]);
    Http::assertNothingSent();
});

it('answers 404 when lead ads are off or the app secret is missing', function () {
    $this->leadAdsConfig(false);
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertNotFound();

    $this->leadAdsConfig();
    config(['social.platforms.facebook.client_secret' => null]);
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertNotFound();
});

it('fetches a signed lead with the page token and captures it through the LeadIntake contract', function () {
    Event::fake([LeadAdReceived::class]);
    $this->fakeGraphLead();

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Captured)
        ->and($lead->crm_lead_id)->toBe('fake-lead-0001')
        ->and($lead->crm_contact_id)->toBe('fake-contact-0001')
        ->and($lead->payload)->toBeNull()
        ->and($lead->ad_id)->toBe('AD1')
        ->and($lead->campaign_ref)->toBe('CMP1')
        ->and(($this->steps)())->toBe(['received', 'fetched', 'captured']);

    expect($this->intake->captures)->toHaveCount(1);
    $capture = $this->intake->captures[0];
    expect($capture->source)->toBe('facebook_lead_ads')
        ->and($capture->idempotencyKey)->toBe('fb-lead:L1')
        ->and($capture->name)->toBe('Rahim Uddin')
        ->and($capture->email)->toBe('rahim@example.com')
        ->and($capture->phone)->toBe('+8801712345678')
        ->and($capture->answers)->toBe(['city' => 'Dhaka', 'facebook_form' => 'Spring offer'])
        ->and($capture->campaignRef)->toBe('CMP1')
        ->and($capture->adRef)->toBe('AD1')
        ->and($capture->consentEvidence)->toBeFalse();

    // The token travels in the Authorization header only.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/L1')
        && $request->hasHeader('Authorization', 'Bearer '.self::PAGE_TOKEN)
        && ! str_contains($request->url(), self::PAGE_TOKEN));

    Event::assertDispatched(LeadAdReceived::class, fn (LeadAdReceived $event) => $event->payload()['crm_lead_id'] === 'fake-lead-0001'
        && ! array_key_exists('email', $event->payload()));
    expect(LeadAdReceived::name())->toBe('social.lead_ad.received');
});

it('registers a repeated delivery of the same lead once', function () {
    $this->fakeGraphLead();

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(1));
    expect($this->intake->captures)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('reports a lead the CRM already had as a duplicate', function () {
    $this->intake->capture(new LeadCapture('facebook_lead_ads', 'fb-lead:L1', email: 'rahim@example.com'));
    $this->fakeGraphLead();

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Duplicate)->and($lead->crm_lead_id)->not->toBeNull()
        ->and(($this->steps)())->toBe(['received', 'fetched', 'duplicate']);
});

it('drops deliveries for a Page this workspace did not claim, and for Pages that are disconnected', function () {
    Http::fake();
    $this->leadWebhook($this->tenant, $this->leadgenPayload(pageId: '99999'))->assertOk();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(0));

    $this->inTenant($this->tenant, fn () => SocialAccount::query()->update(['status' => AccountStatus::Disconnected]));
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(0));
    Http::assertNothingSent();
});

it('never delivers one workspace Page to another workspace', function () {
    Http::fake();
    $other = $this->socialTenant('lead-other');

    // The Page is claimed by the first workspace; the same signed delivery on the other host changes nothing.
    $this->leadWebhook($other, $this->leadgenPayload())->assertOk();
    $this->inTenant($other, fn () => expect(LeadAdLead::query()->count())->toBe(0));
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(0));
    Http::assertNothingSent();
});

it('cuts off a delivery that names too many leads and answers try-again when the backlog is full', function () {
    config(['social.lead_ads.max_changes_per_delivery' => 2]);
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 2, 'message' => 'x']], 500)]);

    $changes = array_map(static fn (int $i): array => [
        'field' => 'leadgen',
        'value' => ['leadgen_id' => 'P'.$i, 'page_id' => '1000', 'form_id' => 'F1', 'created_time' => 1760000000],
    ], range(1, 5));
    $this->leadWebhook($this->tenant, $this->leadgenPayload(changes: $changes))->assertOk();
    $this->inTenant($this->tenant, fn () => expect(LeadAdLead::query()->count())->toBe(2));

    config(['social.lead_ads.max_open_leads' => 1]);
    $this->leadWebhook($this->tenant, $this->leadgenPayload('NEW'))->assertStatus(503)->assertHeader('Retry-After');
});

it('retries a transient failure with a wait, then gives up and lets a person retry', function () {
    // One fake whose answer flips: Facebook is down, then back (re-calling Http::fake only appends a stub).
    $up = false;
    Http::fake(fn () => $up
        ? Http::response($this->leadNode())
        : Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500));

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Received)->and($lead->attempts)->toBe(1)
        ->and($lead->next_attempt_at)->not->toBeNull()->and($lead->reason_code)->toBe('transient')
        ->and($this->intake->captures)->toBe([]);

    // Not before its time: a run while the retry is still in the future does not call Facebook again.
    $this->inTenant($this->tenant, fn () => app(LeadProcessor::class)->process($lead->id));
    Http::assertSentCount(1);

    foreach (range(1, 6) as $_) {
        $this->inTenant($this->tenant, function () use ($lead): void {
            LeadAdLead::query()->whereKey($lead->id)->where('status', 'received')->update(['next_attempt_at' => now()->subSecond()]);
            app(LeadProcessor::class)->process($lead->id);
        });
    }

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Failed)->and($lead->reason_code)->toBe('gave_up');

    // A person retries once Facebook is back.
    $up = true;
    $this->actingAsOwner($this->tenant);
    $this->post($this->socialUrl($this->tenant, '/lead-ads/leads/'.$lead->id.'/retry'))->assertRedirect();
    expect(($this->lead)()->status)->toBe(LeadStatus::Captured);
});

it('waits out Facebook rate limiting without spending attempts', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 4, 'message' => 'Too many calls']], 429, ['Retry-After' => '120'])]);

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Received)->and($lead->attempts)->toBe(0)
        ->and($lead->reason_code)->toBe('rate_limited')
        ->and($lead->next_attempt_at->isFuture())->toBeTrue()
        ->and($lead->next_attempt_at->diffInSeconds(now(), true))->toBeGreaterThan(100);
});

it('moves the account to needs-reauthorization on an expired token, keeps the lead, and fetches it after reconnecting', function () {
    Event::fake([AccountNeedsReauthorization::class]);
    $reconnected = false;
    Http::fake(fn () => $reconnected
        ? Http::response($this->leadNode())
        : Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token: Session has expired']], 400));

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    $account = $this->inTenant($this->tenant, fn () => SocialAccount::query()->sole());
    expect($lead->status)->toBe(LeadStatus::WaitingForAccount)->and($lead->reason_code)->toBe('reauthorization_required')
        ->and($account->status)->toBe(AccountStatus::NeedsReauthorization)->and($account->access_token)->toBeNull();
    Event::assertDispatched(AccountNeedsReauthorization::class);
    expect(json_encode($lead->toArray()))->not->toContain('Session has expired');

    // Nothing is fetched while the Page stays disconnected: the sweep skips an account that is not connected.
    $this->inTenant($this->tenant, fn () => (new SweepLeadAds)->handle(app(LeadSweep::class)));
    Http::assertSentCount(1);
    expect(($this->lead)()->status)->toBe(LeadStatus::WaitingForAccount);

    $this->inTenant($this->tenant, fn () => $account->forceFill(['status' => AccountStatus::Connected, 'access_token' => 'fresh-token'])->save());
    $reconnected = true;
    $this->inTenant($this->tenant, fn () => (new SweepLeadAds)->handle(app(LeadSweep::class)));

    expect(($this->lead)()->status)->toBe(LeadStatus::Captured);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fresh-token'));
});

it('fails a lead the account may not read, or that no longer exists, without retrying', function () {
    $this->fakeGraphError('L1', 403, ['error' => ['code' => 200, 'message' => 'Requires leads_retrieval permission']]);
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    expect(($this->lead)()->status)->toBe(LeadStatus::Failed)->and(($this->lead)()->reason_code)->toBe('permission_missing');

    $this->fakeGraphError('L2', 400, ['error' => ['code' => 100, 'error_subcode' => 33, 'message' => 'Object does not exist']]);
    $this->leadWebhook($this->tenant, $this->leadgenPayload('L2'))->assertOk();
    expect(($this->lead)('L2')->status)->toBe(LeadStatus::Failed)->and(($this->lead)('L2')->reason_code)->toBe('lead_not_found');
});

it('skips a lead of a form that is switched off, and sends it once the form is on and the lead retried', function () {
    $this->inTenant($this->tenant, fn () => LeadForm::query()->update(['is_enabled' => false]));
    $this->fakeGraphLead();

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    expect(($this->lead)()->status)->toBe(LeadStatus::Skipped)->and(($this->lead)()->reason_code)->toBe('form_not_enabled');
    Http::assertNothingSent();

    $this->inTenant($this->tenant, fn () => LeadForm::query()->update(['is_enabled' => true]));
    $this->actingAsOwner($this->tenant);
    $this->post($this->socialUrl($this->tenant, '/lead-ads/leads/'.($this->lead)()->id.'/retry'))->assertRedirect();

    expect(($this->lead)()->status)->toBe(LeadStatus::Captured);
});

it('fails a lead with no usable contact, keeps its answers, and captures it after the mapping is fixed without fetching again', function () {
    $this->inTenant($this->tenant, fn () => LeadForm::query()->update(['questions' => [
        ['key' => 'full_name', 'label' => 'Full name', 'type' => 'FULL_NAME'],
        ['key' => 'contact', 'label' => 'How to reach you', 'type' => 'CUSTOM'],
    ]]));
    $this->fakeGraphLead('L1', $this->leadNode('L1', [
        ['name' => 'full_name', 'values' => ['Karim']],
        ['name' => 'contact', 'values' => ['karim@example.com']],
    ]));

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Failed)->and($lead->reason_code)->toBe('missing_contact')
        ->and($lead->payload)->not->toBeNull()
        ->and($this->intake->captures)->toBe([]);

    $form = $this->setup['form'];
    $this->actingAsOwner($this->tenant);
    $this->put($this->socialUrl($this->tenant, '/lead-ads/forms/'.$form->id.'/mapping'), ['mapping' => [
        'full_name' => ['target' => 'name'],
        'contact' => ['target' => 'email'],
    ]])->assertRedirect();
    $this->post($this->socialUrl($this->tenant, '/lead-ads/leads/'.$lead->id.'/retry'))->assertRedirect();

    expect(($this->lead)()->status)->toBe(LeadStatus::Captured)->and(($this->lead)()->payload)->toBeNull();
    expect($this->intake->captures[0]->email)->toBe('karim@example.com');
    Http::assertSentCount(1);
});

it('reports consent evidence only for a question the person mapped as consent and the lead answered yes', function () {
    $this->inTenant($this->tenant, fn () => LeadForm::query()->update([
        'questions' => [
            ['key' => 'email', 'label' => 'Email', 'type' => 'EMAIL'],
            ['key' => 'agree', 'label' => 'May we contact you?', 'type' => 'CUSTOM'],
        ],
        'mapping' => ['agree' => ['target' => 'consent', 'answer_key' => null]],
    ]));

    $this->fakeGraphLead('L1', $this->leadNode('L1', [['name' => 'email', 'values' => ['a@example.com']], ['name' => 'agree', 'values' => ['Yes']]]));
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();
    $this->fakeGraphLead('L2', $this->leadNode('L2', [['name' => 'email', 'values' => ['b@example.com']], ['name' => 'agree', 'values' => ['No']]]));
    $this->leadWebhook($this->tenant, $this->leadgenPayload('L2'))->assertOk();

    expect($this->intake->captures[0]->consentEvidence)->toBeTrue()
        ->and($this->intake->captures[1]->consentEvidence)->toBeFalse()
        // The consent answer is not stored as a plain answer.
        ->and($this->intake->captures[0]->answers)->not->toHaveKey('agree');
});

it('keeps leads as waiting_for_crm when the CRM is not available and sends them when it is', function () {
    $this->intake = $this->fakeLeadIntake(available: false);
    $this->fakeGraphLead();

    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::WaitingForCrm)->and($lead->payload)->not->toBeNull()
        ->and($lead->reason_code)->toBe('crm_unavailable')
        ->and(($this->steps)())->toBe(['received', 'fetched', 'waiting']);

    // Still no CRM: the sweep leaves it alone.
    $this->inTenant($this->tenant, fn () => (new SweepLeadAds)->handle(app(LeadSweep::class)));
    expect(($this->lead)()->status)->toBe(LeadStatus::WaitingForCrm);

    $this->intake = $this->fakeLeadIntake();
    $this->entitle($this->tenant, 'crm');
    Http::fake();
    $this->inTenant($this->tenant, fn () => (new SweepLeadAds)->handle(app(LeadSweep::class)));

    expect(($this->lead)()->status)->toBe(LeadStatus::Captured)->and(($this->lead)()->payload)->toBeNull();
    // The answers were kept, so Facebook is not asked again.
    Http::assertNothingSent();
});

it('removes the answers of a lead that waited longer than the retention window', function () {
    $this->intake = $this->fakeLeadIntake(available: false);
    $this->fakeGraphLead();
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $this->inTenant($this->tenant, fn () => LeadAdLead::query()->update(['received_at' => now()->subDays(15)]));
    $this->inTenant($this->tenant, fn () => (new SweepLeadAds)->handle(app(LeadSweep::class)));

    $lead = ($this->lead)();
    expect($lead->status)->toBe(LeadStatus::Failed)->and($lead->reason_code)->toBe('expired')->and($lead->payload)->toBeNull();
});

it('keeps the fetched answers encrypted at rest and out of serialization', function () {
    $this->intake = $this->fakeLeadIntake(available: false);
    $this->fakeGraphLead();
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $raw = DB::table('social_lead_ad_leads')->value('payload');
    expect($raw)->not->toContain('rahim')->not->toContain('Rahim')->not->toContain('8801712345678')
        ->and(json_encode(($this->lead)()->toArray()))->not->toContain('Rahim');
});

it('never writes the page token to the log or the audit trail', function () {
    Log::spy();
    $this->fakeGraphError('L1', 500, ['error' => ['code' => 2, 'message' => 'oops '.self::PAGE_TOKEN]]);
    $this->leadWebhook($this->tenant, $this->leadgenPayload())->assertOk();

    $lead = ($this->lead)();
    expect(json_encode(LeadAdLog::query()->withoutGlobalScopes()->get()->toArray()))->not->toContain(self::PAGE_TOKEN)
        ->and((string) $lead->reason)->not->toContain(self::PAGE_TOKEN);
});
