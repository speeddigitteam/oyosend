<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\CommunicationSettings;
use App\Modules\Communication\Models\ChannelConnection;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCommunication;

uses(InteractsWithCommunication::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('comm-set');
    config(['communication.credentials' => [
        'sms_main' => ['secret' => 'TOP-SECRET-VALUE', 'tenants' => null],
        'sms_private' => ['secret' => 'PRIVATE-VALUE', 'tenants' => ['someone-else']],
        'sms_empty' => ['secret' => '', 'tenants' => null],
    ]]);
    $this->actingAsOwner($this->tenant);
    $this->channel = fn (array $overrides = []) => [
        'provider' => 'fake_sms', 'is_enabled' => true, 'settings' => ['sender_id' => 'ACME'],
        'credential_ref' => 'sms_main', 'webhook_secret_ref' => null, 'rate_limit_per_minute' => 10, ...$overrides,
    ];
});

it('shows each channel as configured or not_configured and never a secret', function () {
    $props = [];
    $this->get($this->commUrl($this->tenant, '/settings'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });
    $channels = collect($props['channels'])->keyBy('channel');

    expect($channels['email']['state'])->toBe('configured')
        ->and($channels['sms']['state'])->toBe('not_configured')
        ->and($channels['sms']['provider'])->toBe('none')
        ->and($channels['whatsapp']['state'])->toBe('not_configured')
        ->and($props['credentialRefs'])->toBe(['sms_main'])
        ->and(json_encode($props))->not->toContain('TOP-SECRET-VALUE')->not->toContain('PRIVATE-VALUE');
});

it('saves a connection holding a reference and settings, never a secret', function () {
    $this->put($this->commUrl($this->tenant, '/settings/channels/sms'), ($this->channel)())->assertRedirect();

    $row = $this->inTenant($this->tenant, fn () => ChannelConnection::query()->sole());
    expect($row->provider)->toBe('fake_sms')
        ->and($row->credential_ref)->toBe('sms_main')
        ->and($row->settings)->toBe(['sender_id' => 'ACME'])
        ->and(json_encode($row->getAttributes()))->not->toContain('TOP-SECRET-VALUE');

    $this->get($this->commUrl($this->tenant, '/settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('channels.1.state', 'configured')->where('channels.1.credentialRef', 'sms_main'));

    // The audit trail names the channel and provider only.
    $trail = $this->auditTrail($this->tenant, 'communication.channel.created');
    expect(json_encode($trail))->not->toContain('TOP-SECRET-VALUE');
});

it('refuses a credential reference the workspace may not use, or one that does not exist', function () {
    foreach (['sms_private', 'sms_empty', 'nope', 'bad ref!'] as $ref) {
        $this->put($this->commUrl($this->tenant, '/settings/channels/sms'), ($this->channel)(['credential_ref' => $ref]))
            ->assertSessionHasErrors('credential_ref');
    }

    expect($this->inTenant($this->tenant, fn () => ChannelConnection::query()->count()))->toBe(0);
});

it('rejects an unknown provider, an invalid setting and a rate limit above the platform ceiling', function () {
    $url = $this->commUrl($this->tenant, '/settings/channels/sms');

    $this->put($url, ($this->channel)(['provider' => 'mystery']))->assertSessionHasErrors('provider');
    $this->put($url, ($this->channel)(['settings' => ['sender_id' => str_repeat('x', 200)]]))->assertSessionHasErrors('settings.sender_id');
    $this->put($url, ($this->channel)(['rate_limit_per_minute' => 5000]))->assertSessionHasErrors('rate_limit_per_minute');
    // A provider of another channel is not available for this one.
    $this->put($url, ($this->channel)(['provider' => 'mail']))->assertSessionHasErrors('provider');
    $this->put($this->commUrl($this->tenant, '/settings/channels/fax'), ($this->channel)())->assertNotFound();
});

it('saves sender identity, quiet hours and footer, and validates them', function () {
    $url = $this->commUrl($this->tenant, '/settings');
    $valid = [
        'from_name' => 'Acme Support', 'reply_to_email' => 'help@acme.test', 'quiet_hours_enabled' => true,
        'quiet_hours_start' => '22:00', 'quiet_hours_end' => '07:30', 'footer_text' => 'Acme Ltd, Dhaka',
    ];

    $this->put($url, $valid)->assertRedirect();
    $this->put($url, [...$valid, 'quiet_hours_start' => '25:99'])->assertSessionHasErrors('quiet_hours_start');
    $this->put($url, [...$valid, 'reply_to_email' => 'not-an-email'])->assertSessionHasErrors('reply_to_email');

    $settings = $this->inTenant($this->tenant, fn () => app(CommunicationSettings::class)->current());
    expect($settings->from_name)->toBe('Acme Support')->and($settings->quiet_hours_end)->toBe('07:30');
});

it('keeps the settings pages behind their permission', function () {
    [$user] = $this->commMember($this->tenant, ['communication.templates.view']);

    $this->actingAsMember($this->tenant, $user)->get($this->commUrl($this->tenant, '/settings'))->assertForbidden();
    $this->put($this->commUrl($this->tenant, '/settings/channels/sms'), ($this->channel)())->assertForbidden();
});
