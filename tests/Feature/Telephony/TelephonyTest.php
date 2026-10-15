<?php

declare(strict_types=1);

use App\Core\Automation\AutomationRegistry;
use App\Core\Contacts\ContactDirectory;
use App\Core\Contacts\Contracts\CustomerRecordProvider;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Telephony\Actions\CallLog;
use App\Modules\Telephony\Actions\ExtensionManager;
use App\Modules\Telephony\Actions\TelephonySettings;
use App\Modules\Telephony\CustomerRecords\CallsSection;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallMode;
use App\Modules\Telephony\Enums\CallStatus;
use App\Modules\Telephony\Events\CallEnded;
use App\Modules\Telephony\Models\Call;
use App\Modules\Telephony\Models\Extension;
use App\Modules\Telephony\Models\TelephonySetting;
use App\Modules\Telephony\Pbx\FakePbxClient;
use App\Modules\Telephony\Pbx\HostResolver;
use App\Modules\Telephony\Pbx\PbxUrlGuard;
use App\Modules\Telephony\Reporting\CallsByMemberReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->tenant = $this->createTenant('tel-home');
    $this->entitle($this->tenant, 'telephony');
    $this->owner = $this->ownerOf($this->tenant);
    $this->member = $this->membershipOf($this->tenant, $this->owner);
    $this->url = fn (string $path = '') => $this->tenantUrl($this->tenant, '/telephony'.$path);

    /** A workspace whose PBX is the fake, with the owner on extension 101. */
    $this->configure = function (array $settings = [], ?string $bridgePhone = null): void {
        $this->inTenant($this->tenant, function () use ($settings, $bridgePhone): void {
            app(TelephonySettings::class)->update([
                'driver' => 'fake', 'wss_url' => 'wss://pbx.example.com:8089/ws', 'sip_domain' => 'pbx.example.com', ...$settings,
            ]);
            app(ExtensionManager::class)->save($this->member, '101', 'sip-secret-101', $bridgePhone);
        });
    };

    /** A signed PBX event, as the PBX would send it. */
    $this->event = function (array $body, string $secret, ?int $at = null) {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = (string) ($at ?? Carbon::now()->timestamp);

        return $this->call('POST', $this->tenantUrl($this->tenant, '/telephony/events'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_OYOSEND_TIMESTAMP' => $timestamp,
            'HTTP_X_OYOSEND_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$raw, $secret),
        ], $raw);
    };
});

it('keeps calls, extensions and settings isolated per tenant', function () {
    $this->assertTenantIsolated(Call::class, fn () => Call::query()->create([
        'direction' => 'outbound', 'mode' => 'softphone', 'status' => 'dialing', 'remote_number' => '+8801711000001', 'started_at' => now(),
    ]));
    $this->assertTenantIsolated(TelephonySetting::class, fn () => TelephonySetting::query()->create(['driver' => 'asterisk']));
    $this->assertTenantIsolated(Extension::class, function ($tenant) {
        $membership = $this->membershipOf($tenant, $this->ownerOf($tenant));

        return Extension::query()->create(['membership_id' => $membership->id, 'extension' => '201']);
    });
});

it('hides another workspace\'s call and extension behind a 404', function () {
    ($this->configure)();
    [$call, $extension] = $this->inTenant($this->tenant, fn () => [
        app(CallLog::class)->start($this->member, '+8801711000001', CallDirection::Outbound, CallMode::Softphone, '101', 'sip-call-1'),
        Extension::query()->sole(),
    ]);
    $intruder = $this->createTenant('tel-intruder');
    $this->entitle($intruder, 'telephony');

    $this->assertNotFoundFromOtherTenant('POST', "telephony/calls/{$call->id}/status", $this->tenant, ['status' => 'ringing'], $intruder);
    $this->assertNotFoundFromOtherTenant('PUT', "telephony/settings/extensions/{$extension->id}", $this->tenant, ['extension' => '102'], $intruder);
    $this->assertNotFoundFromOtherTenant('DELETE', "telephony/settings/extensions/{$extension->id}", $this->tenant, [], $intruder);
});

it('returns 404 for a workspace that is not entitled to telephony', function () {
    $other = $this->createTenant('tel-off');

    $this->actingAsOwner($other)->get($this->tenantUrl($other, '/telephony/dialer'))->assertNotFound();
});

it('says plainly that calling is not set up', function () {
    $this->actingAsOwner($this->tenant)->get(($this->url)('/dialer'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Telephony/Pages/Dialer')
            ->where('setup.workspaceSoftphone', false)
            ->where('setup.workspaceBridge', false)
            ->where('setup.extension', null));

    $this->actingAsOwner($this->tenant)->getJson(($this->url)('/softphone/credentials'))
        ->assertStatus(409)->assertJson(['status' => 'not_configured']);
    $this->actingAsOwner($this->tenant)->postJson(($this->url)('/bridge'), ['number' => '01711000001'])
        ->assertStatus(503)->assertJson(['status' => 'unavailable']);
});

it('keeps every secret write-only and refuses an unsafe PBX address', function () {
    $this->actingAsOwner($this->tenant)->put(($this->url)('/settings'), [
        'driver' => 'asterisk', 'api_url' => 'https://pbx.example.com:8089', 'api_username' => 'oyosend', 'api_secret' => 'ari-secret',
        'turn_url' => 'turn:turn.example.com:3478', 'turn_username' => 'u', 'turn_credential' => 'turn-secret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // Saving again without the secrets keeps them.
    $this->actingAsOwner($this->tenant)->put(($this->url)('/settings'), ['driver' => 'asterisk', 'api_url' => 'https://pbx.example.com:8089', 'api_username' => 'oyosend'])
        ->assertSessionHasNoErrors();

    $settings = $this->inTenant($this->tenant, fn () => TelephonySetting::query()->sole());
    expect($settings->api_secret)->toBe('ari-secret')->and($settings->turn_credential)->toBe('turn-secret')
        ->and(DB::table('telephony_settings')->value('api_secret'))->not->toContain('ari-secret');

    $this->actingAsOwner($this->tenant)->get(($this->url)('/settings'))->assertOk()
        ->assertDontSee('ari-secret')->assertDontSee('turn-secret')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('settings.hasApiSecret', true)->where('settings.bridgeReady', true));

    $this->actingAsOwner($this->tenant)->put(($this->url)('/settings'), ['driver' => 'asterisk', 'api_url' => 'http://pbx.example.com'])
        ->assertSessionHasErrors('api_url');
    $this->actingAsOwner($this->tenant)->put(($this->url)('/settings'), ['driver' => 'asterisk', 'api_url' => 'https://user:pass@pbx.example.com'])
        ->assertSessionHasErrors('api_url');
});

it('refuses a PBX that resolves to a private address at call time', function () {
    $resolver = new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return $host === 'inside.example.com' ? ['10.0.0.5'] : ['203.0.114.10'];
        }
    };
    $guard = new PbxUrlGuard($resolver);

    expect($guard->inspect('https://inside.example.com:8089'))->toBe('The host resolves to a private or reserved address.')
        ->and($guard->inspect('https://127.0.0.1:8089'))->toBe('The host resolves to a private or reserved address.')
        ->and($guard->inspect('https://pbx.example.com:8089'))->toBe(['host' => 'pbx.example.com', 'port' => 8089, 'addresses' => ['203.0.114.10']]);
});

it('shows the webhook secret once, right after it is created', function () {
    $this->actingAsOwner($this->tenant)->post(($this->url)('/settings/webhook-secret'))->assertRedirect();

    $secret = $this->inTenant($this->tenant, fn () => TelephonySetting::query()->sole()->webhook_secret);
    $this->actingAsOwner($this->tenant)->get(($this->url)('/settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('revealedWebhookSecret', $secret)->where('settings.hasWebhookSecret', true));
    $this->actingAsOwner($this->tenant)->get(($this->url)('/settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('revealedWebhookSecret', null));
});

it('gives extensions with a write-only password and a normalised own phone', function () {
    $this->actingAsOwner($this->tenant)->post(($this->url)('/settings/extensions'), [
        'membership_id' => $this->member->id, 'extension' => '101', 'sip_password' => 'sip-secret', 'bridge_phone' => '01711-000002',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $extension = $this->inTenant($this->tenant, fn () => Extension::query()->sole());
    expect($extension->bridge_phone)->toBe('+8801711000002')->and($extension->sip_password)->toBe('sip-secret');

    $this->actingAsOwner($this->tenant)->put(($this->url)("/settings/extensions/{$extension->id}"), ['extension' => '102', 'bridge_phone' => ''])
        ->assertSessionHasNoErrors();
    expect($extension->refresh()->extension)->toBe('102')->and($extension->sip_password)->toBe('sip-secret')->and($extension->bridge_phone)->toBeNull();

    $this->actingAsOwner($this->tenant)->get(($this->url)('/settings'))->assertDontSee('sip-secret');
    $this->actingAsOwner($this->tenant)->put(($this->url)("/settings/extensions/{$extension->id}"), ['extension' => '102', 'bridge_phone' => 'call me'])
        ->assertSessionHasErrors('bridge_phone');
});

it('hands the softphone credentials only to the member they belong to', function () {
    ($this->configure)(['stun_urls' => ['stun:stun.example.com:3478']]);

    $this->actingAsOwner($this->tenant)->getJson(($this->url)('/softphone/credentials'))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson(['status' => 'ok', 'config' => [
            'wssUrl' => 'wss://pbx.example.com:8089/ws', 'sipUri' => 'sip:101@pbx.example.com', 'authorizationUser' => '101',
            'password' => 'sip-secret-101', 'iceServers' => [['urls' => 'stun:stun.example.com:3478']],
        ]]);

    // Another member with the permission but no extension gets nothing of the owner's.
    $role = $this->createRole($this->tenant, 'Caller', ['telephony.calls.make']);
    $caller = $this->addMember($this->tenant, $role);
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $caller)
        ->getJson(($this->url)('/softphone/credentials'))->assertStatus(409)->assertDontSee('sip-secret-101');

    // The dialer page never carries the password.
    $this->startNewBrowserSession()->actingAsOwner($this->tenant)->get(($this->url)('/dialer'))->assertDontSee('sip-secret-101');
});

it('places a bridge call through the PBX, ringing the member\'s own phone first', function () {
    ($this->configure)([], '+8801711000009');
    $contact = $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->createContact(['first_name' => 'Karim', 'phone' => '01711-000001'], null));

    $response = $this->actingAsOwner($this->tenant)->postJson(($this->url)('/bridge'), ['number' => '01711000001'])
        ->assertCreated()->assertJson(['status' => 'ringing']);

    $call = $this->inTenant($this->tenant, fn () => Call::query()->sole());
    expect(app(FakePbxClient::class)->originated)->toBe([['call_id' => $call->id, 'agent' => '+8801711000009', 'number' => '+8801711000001']])
        ->and($call->mode)->toBe(CallMode::Bridge)
        ->and($call->contact_id)->toBe($contact->id)
        ->and($call->owner_id)->toBe($this->member->id)
        ->and($response->json('id'))->toBe($call->id);

    app(FakePbxClient::class)->refuse = true;
    $this->actingAsOwner($this->tenant)->postJson(($this->url)('/bridge'), ['number' => '01711000001'])->assertStatus(503);
    expect($this->inTenant($this->tenant, fn () => Call::query()->latest('id')->first()->status))->toBe(CallStatus::Failed);

    $this->actingAsOwner($this->tenant)->postJson(($this->url)('/bridge'), ['number' => '12'])->assertStatus(422);
});

it('logs a softphone call from the member\'s browser, and only theirs', function () {
    ($this->configure)();
    Event::fake([CallEnded::class]);

    $id = $this->actingAsOwner($this->tenant)->postJson(($this->url)('/calls'), [
        'number' => '+8801711000001', 'direction' => 'outbound', 'client_call_id' => 'abc123@pbx',
    ])->assertCreated()->json('id');

    // The same SIP call again is the same row.
    $this->actingAsOwner($this->tenant)->postJson(($this->url)('/calls'), ['number' => '+8801711000001', 'direction' => 'outbound', 'client_call_id' => 'abc123@pbx'])
        ->assertOk()->assertJson(['id' => $id]);

    $this->actingAsOwner($this->tenant)->postJson(($this->url)("/calls/{$id}/status"), ['status' => 'answered'])->assertOk();
    $this->travel(65)->seconds();
    $this->actingAsOwner($this->tenant)->postJson(($this->url)("/calls/{$id}/status"), ['status' => 'completed'])->assertOk()->assertJson(['status' => 'completed']);

    $call = $this->inTenant($this->tenant, fn () => Call::query()->findOrFail($id));
    expect($call->duration_seconds)->toBeGreaterThanOrEqual(65)->and($call->confirmed_at)->toBeNull();
    Event::assertDispatchedTimes(CallEnded::class, 1);

    // Someone else's call is not found.
    $role = $this->createRole($this->tenant, 'Caller', ['telephony.calls.make']);
    $caller = $this->addMember($this->tenant, $role);
    $this->startNewBrowserSession()->actingAsMember($this->tenant, $caller)
        ->postJson(($this->url)("/calls/{$id}/status"), ['status' => 'failed'])->assertNotFound();
});

it('accepts only signed, fresh PBX events, applies each once and logs a missed call', function () {
    ($this->configure)();
    $secret = $this->inTenant($this->tenant, fn () => app(TelephonySettings::class)->rotateWebhookSecret());
    $contact = $this->inTenant($this->tenant, fn () => app(ContactDirectory::class)->createContact(['first_name' => 'Karim', 'phone' => '+8801711000001'], null));
    Event::fake([CallEnded::class]);

    $ringing = ['event_id' => 'e1', 'call_id' => 'pbx-77', 'type' => 'ringing', 'direction' => 'inbound', 'from' => '01711000001', 'extension' => '101'];

    ($this->event)($ringing, 'wrong-secret')->assertStatus(401);
    ($this->event)($ringing, $secret, Carbon::now()->subMinutes(10)->timestamp)->assertStatus(401);
    ($this->event)($ringing, $secret)->assertOk()->assertJson(['status' => 'accepted']);
    ($this->event)($ringing, $secret)->assertOk()->assertJson(['status' => 'duplicate']);

    ($this->event)(['event_id' => 'e2', 'call_id' => 'pbx-77', 'type' => 'ended', 'status' => 'completed'], $secret)->assertOk();

    $call = $this->inTenant($this->tenant, fn () => Call::query()->sole());
    expect($call->direction)->toBe(CallDirection::Inbound)
        ->and($call->status)->toBe(CallStatus::Missed)
        ->and($call->owner_id)->toBe($this->member->id)
        ->and($call->contact_id)->toBe($contact->id)
        ->and($call->confirmed_at)->not->toBeNull();
    Event::assertDispatchedTimes(CallEnded::class, 1);

    // Once the PBX has spoken, the browser cannot change the call.
    $this->actingAsOwner($this->tenant)->postJson(($this->url)("/calls/{$call->id}/status"), ['status' => 'answered'])->assertOk()->assertJson(['status' => 'missed']);
});

it('refuses events while no secret is set', function () {
    ($this->event)(['event_id' => 'e1', 'call_id' => 'x', 'type' => 'ringing'], 'anything')->assertNotFound();
});

it('lets a viewer see the log but not call or change settings', function () {
    $role = $this->createRole($this->tenant, 'Call viewer', ['telephony.calls.view']);
    $viewer = $this->addMember($this->tenant, $role);

    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/calls'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Modules/Telephony/Pages/Calls/Index')->where('canCall', false));
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/dialer'))->assertForbidden();
    $this->actingAsMember($this->tenant, $viewer)->get(($this->url)('/settings'))->assertForbidden();
});

it('publishes its trigger, report and customer section', function () {
    expect(app(AutomationRegistry::class)->triggers())->toHaveKey('telephony.call.ended')
        ->and(collect(app()->tagged(ReportProvider::TAG))->map(fn ($provider) => $provider::class)->all())->toContain(CallsByMemberReport::class)
        ->and(collect(app()->tagged(CustomerRecordProvider::TAG))->map(fn ($provider) => $provider::class)->all())->toContain(CallsSection::class);
});
