<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\ChannelConnections;
use App\Modules\Communication\Actions\MessageDispatcher;
use App\Modules\Communication\Actions\OutboundRequest;
use App\Modules\Communication\Actions\Outbox;
use App\Modules\Communication\Channels\CredentialResolver;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use App\Modules\Communication\Models\ChannelConnection;
use App\Modules\Communication\Models\Message;
use App\Modules\Communication\Models\ProviderAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithCommunication;
use Tests\Support\InteractsWithProviderAccounts;

/*
 * Operator-managed provider accounts as a workspace sees them (ADR 0013): the resolver, the channel settings
 * and the outbox. ProviderAccount is platform data, so there is no tenant-isolation helper to apply; what
 * matters is which workspace may USE an account, and that is tested here.
 */

uses(InteractsWithCommunication::class, InteractsWithProviderAccounts::class);

beforeEach(function () {
    $this->tenant = $this->commTenant('acct-ws');
    $this->other = $this->createTenant('acct-other');
    $this->entitle($this->other, 'communication');
    $this->queue = fn (array $overrides = []) => $this->inTenant($this->tenant, fn () => app(Outbox::class)->queue(new OutboundRequest(...[
        'channel' => Channel::Sms,
        'purpose' => MessagePurpose::Transactional,
        'recipient' => '+8801711000000',
        'subject' => null,
        'body' => 'Your order is on its way.',
        'idempotencyKey' => 'sms-'.bin2hex(random_bytes(4)),
        ...$overrides,
    ])));
    $this->save = fn (array $overrides = []) => [
        'provider' => 'twilio', 'is_enabled' => true, 'settings' => [], 'credential_ref' => null,
        'webhook_secret_ref' => null, 'rate_limit_per_minute' => null, ...$overrides,
    ];
    $this->resolver = fn (Tenant $tenant, Closure $callback) => $this->inTenant($tenant, fn () => $callback(app(CredentialResolver::class)));
});

describe('CredentialResolver', function () {
    it('resolves an account reference with its settings for the provider it was created for', function () {
        $account = $this->makeAccount();

        $credentials = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => $r->resolve($account->reference(), 'twilio'));

        expect($credentials)->not->toBeNull()
            ->and($credentials->secret())->toBe(self::TWILIO_TOKEN)
            ->and($credentials->settings())->toEqual(['account_sid' => self::TWILIO_SID, 'from' => '+15005550006']);
    });

    it('never hands an account to another provider', function () {
        $account = $this->makeAccount();

        $result = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => [
            'whatsapp' => $r->resolve($account->reference(), 'whatsapp_cloud'),
            'http' => $r->resolve($account->reference(), 'http_sms'),
            'attribute' => $r->attribute($account->reference(), 'verify_token', 'whatsapp_cloud'),
            'webhook' => $r->webhookSecret($account->reference(), 'whatsapp_cloud'),
            'usable' => $r->isUsableWith($account->reference(), Channel::Whatsapp, 'whatsapp_cloud'),
            'usableHttp' => $r->isUsableWith($account->reference(), Channel::Sms, 'http_sms'),
            'usableOwn' => $r->isUsableWith($account->reference(), Channel::Sms, 'twilio'),
            'wrongChannel' => $r->isUsableWith($account->reference(), Channel::Email, 'twilio'),
        ]);

        expect($result['whatsapp'])->toBeNull()
            ->and($result['http'])->toBeNull()
            ->and($result['attribute'])->toBeNull()
            ->and($result['webhook'])->toBeNull()
            ->and($result['usable'])->toBeFalse()
            ->and($result['usableHttp'])->toBeFalse()
            ->and($result['wrongChannel'])->toBeFalse()
            ->and($result['usableOwn'])->toBeTrue();
    });

    it('respects the workspaces an account is limited to', function () {
        $account = $this->makeAccount(['tenant_ids' => [(string) $this->tenant->id]]);

        $allowed = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => $r->resolve($account->reference(), 'twilio'));
        $denied = ($this->resolver)($this->other, fn (CredentialResolver $r) => [
            $r->resolve($account->reference(), 'twilio'),
            $r->resolve($account->reference()),
            $r->isAvailable($account->reference()),
            $r->isUsableWith($account->reference(), Channel::Sms, 'twilio'),
            $r->available(),
            $r->accountLabels(),
        ]);

        expect($allowed)->not->toBeNull()
            ->and($denied[0])->toBeNull()
            ->and($denied[1])->toBeNull()
            ->and($denied[2])->toBeFalse()
            ->and($denied[3])->toBeFalse()
            ->and($denied[4])->toBe([])
            ->and($denied[5])->toBe([]);
    });

    it('treats a disabled account as unavailable', function () {
        $account = $this->makeAccount(['is_enabled' => false, 'name' => 'Off']);

        $result = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => [
            $r->resolve($account->reference(), 'twilio'),
            $r->isUsableWith($account->reference(), Channel::Sms, 'twilio'),
            $r->available(),
        ]);

        expect($result[0])->toBeNull()->and($result[1])->toBeFalse()->and($result[2])->toBe([]);
    });

    it('lists only the enabled accounts this workspace may use, with the environment references', function () {
        config(['communication.credentials' => [
            'sms_env' => ['secret' => 'env-secret', 'tenants' => null],
            'sms_env_private' => ['secret' => 'env-private', 'tenants' => ['someone-else']],
        ]]);
        $visible = $this->makeAccount(['name' => 'Visible']);
        $mine = $this->makeAccount(['name' => 'Only mine', 'tenant_ids' => [(string) $this->tenant->id]]);
        $this->makeAccount(['name' => 'Only theirs', 'tenant_ids' => [(string) $this->other->id]]);
        $this->makeAccount(['name' => 'Disabled', 'is_enabled' => false]);
        $this->makeAccount(['name' => 'No secret', 'secrets' => null]);

        [$available, $labels] = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => [$r->available(), $r->accountLabels()]);
        $theirs = ($this->resolver)($this->other, fn (CredentialResolver $r) => $r->accountLabels());

        $expected = [$visible->reference(), $mine->reference(), 'sms_env'];
        sort($expected);

        expect($available)->toBe($expected)
            ->and($labels)->toBe([$mine->reference() => 'Only mine', $visible->reference() => 'Visible'])
            ->and(array_values($theirs))->toContain('Visible', 'Only theirs')->not->toContain('Only mine');
    });

    it('still resolves environment references for any provider and restricts them by workspace slug', function () {
        config(['communication.credentials' => [
            'sms_env' => ['secret' => 'env-secret', 'tenants' => null, 'verify_token' => 'env-verify'],
            'sms_slug' => ['secret' => 'slug-secret', 'tenants' => ['acct-ws']],
        ]]);

        $mine = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => [
            $r->resolve('sms_env', 'twilio')?->secret(),
            $r->resolve('sms_env')?->settings(),
            $r->resolve('sms_slug', 'http_sms')?->secret(),
            $r->webhookSecret('sms_env', 'whatsapp_cloud')?->secret(),
            $r->attribute('sms_env', 'verify_token', 'whatsapp_cloud')?->secret(),
            $r->attribute('sms_env', 'secret', 'whatsapp_cloud'),
            $r->isUsableWith('sms_env', Channel::Sms, 'twilio'),
            $r->resolve('nope'),
            $r->resolve(null),
        ]);
        $theirs = ($this->resolver)($this->other, fn (CredentialResolver $r) => $r->resolve('sms_slug'));

        expect($mine)->toBe(['env-secret', [], 'slug-secret', 'env-secret', 'env-verify', null, true, null, null])
            ->and($theirs)->toBeNull();
    });

    it('reads the webhook secret and verify token of a WhatsApp account, apart from the access token', function () {
        $account = $this->makeAccount([
            'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'name' => 'Meta', 'settings' => null,
            'secrets' => ['secret' => 'access-token', 'webhook_secret' => 'app-secret', 'verify_token' => 'verify-1'],
        ]);
        $bare = $this->makeAccount([
            'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'name' => 'Meta bare', 'settings' => null,
            'secrets' => ['secret' => 'access-token-2'],
        ]);

        $result = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => [
            $r->resolve($account->reference(), 'whatsapp_cloud')?->secret(),
            $r->webhookSecret($account->reference(), 'whatsapp_cloud')?->secret(),
            $r->attribute($account->reference(), 'verify_token', 'whatsapp_cloud')?->secret(),
            // Only listed attributes can be read.
            $r->attribute($account->reference(), 'webhook_secret', 'whatsapp_cloud'),
            // An account without a webhook secret does NOT fall back to the access token.
            $r->webhookSecret($bare->reference(), 'whatsapp_cloud'),
            $r->attribute($bare->reference(), 'verify_token', 'whatsapp_cloud'),
        ]);

        expect($result)->toBe(['access-token', 'app-secret', 'verify-1', null, null, null]);
    });

    it('rejects malformed account references', function (string $reference) {
        $this->makeAccount();

        $result = ($this->resolver)($this->tenant, fn (CredentialResolver $r) => $r->resolve($reference, 'twilio'));

        expect($result)->toBeNull();
    })->with(['too short' => 'acct_abc', 'upper-case id' => 'acct_01HZZZZZZZZZZZZZZZZZZZZZZZ', 'unknown id' => 'acct_01hzzzzzzzzzzzzzzzzzzzzzzz', 'spaces' => 'acct_ x']);
});

describe('channel connections', function () {
    it('accepts an account only for its own provider and channel', function () {
        $sms = $this->makeAccount(['name' => 'SMS']);
        $smtp = $this->makeAccount([
            'channel' => 'email', 'provider' => 'smtp', 'name' => 'Mail',
            'settings' => ['host' => 'smtp.mail-example.com', 'port' => 587, 'encryption' => 'starttls', 'from_address' => 'a@example.com'],
            'secrets' => ['secret' => 'pw'],
        ]);
        $this->actingAsOwner($this->tenant);
        $url = $this->commUrl($this->tenant, '/settings/channels/sms');

        // The Twilio account is refused for the HTTP gateway provider, for the test double and as an email credential.
        $this->put($url, ($this->save)(['provider' => 'http_sms', 'credential_ref' => $sms->reference(), 'settings' => []]))
            ->assertSessionHasErrors('credential_ref');
        $this->put($url, ($this->save)(['provider' => 'fake_sms', 'credential_ref' => $sms->reference()]))
            ->assertSessionHasErrors('credential_ref');
        $this->put($this->commUrl($this->tenant, '/settings/channels/email'), ($this->save)(['provider' => 'smtp', 'credential_ref' => $sms->reference()]))
            ->assertSessionHasErrors('credential_ref');
        // An SMTP account is refused for an SMS provider.
        $this->put($url, ($this->save)(['credential_ref' => $smtp->reference()]))->assertSessionHasErrors('credential_ref');
        // A webhook secret reference follows the same rule.
        $this->put($url, ($this->save)(['credential_ref' => $sms->reference(), 'webhook_secret_ref' => $smtp->reference()]))
            ->assertSessionHasErrors('webhook_secret_ref');
        expect($this->inTenant($this->tenant, fn () => ChannelConnection::query()->count()))->toBe(0);

        $this->put($url, ($this->save)(['credential_ref' => $sms->reference()]))->assertSessionHasNoErrors();
        $this->put($this->commUrl($this->tenant, '/settings/channels/email'), ($this->save)(['provider' => 'smtp', 'credential_ref' => $smtp->reference()]))
            ->assertSessionHasNoErrors();

        $rows = $this->inTenant($this->tenant, fn () => ChannelConnection::query()->get()->keyBy(fn ($row) => $row->channel->value));
        expect($rows['sms']->credential_ref)->toBe($sms->reference())
            ->and($rows['sms']->provider)->toBe('twilio')
            ->and($rows['email']->credential_ref)->toBe($smtp->reference())
            ->and(json_encode($rows->map->getAttributes()->all()))->not->toContain(self::TWILIO_TOKEN);
    });

    it('refuses an account that is disabled, limited to another workspace or does not exist', function () {
        $disabled = $this->makeAccount(['name' => 'Off', 'is_enabled' => false]);
        $theirs = $this->makeAccount(['name' => 'Theirs', 'tenant_ids' => [(string) $this->other->id]]);
        $this->actingAsOwner($this->tenant);
        $url = $this->commUrl($this->tenant, '/settings/channels/sms');

        foreach ([$disabled->reference(), $theirs->reference(), 'acct_01hzzzzzzzzzzzzzzzzzzzzzzz', 'acct_'.strtoupper($theirs->id)] as $reference) {
            $this->put($url, ($this->save)(['credential_ref' => $reference]))->assertSessionHasErrors('credential_ref');
        }

        expect($this->inTenant($this->tenant, fn () => ChannelConnection::query()->count()))->toBe(0);
    });

    it('reports configured and not_configured as the account comes and goes', function () {
        $account = $this->makeAccount();
        $this->actingAsOwner($this->tenant);
        $this->put($this->commUrl($this->tenant, '/settings/channels/sms'), ($this->save)(['credential_ref' => $account->reference()]))
            ->assertSessionHasNoErrors();

        $state = fn () => $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->state(Channel::Sms)->value);
        expect($state())->toBe('configured');

        $account->update(['is_enabled' => false]);
        expect($state())->toBe('not_configured');

        $account->update(['is_enabled' => true, 'tenant_ids' => [(string) $this->other->id]]);
        expect($state())->toBe('not_configured');

        $account->update(['tenant_ids' => null]);
        expect($state())->toBe('configured');

        $account->delete();
        expect($state())->toBe('not_configured');
    });

    it('shows account names to the workspace, never settings or secrets', function () {
        $account = $this->makeAccount(['name' => 'Operator Twilio']);
        $this->makeAccount(['name' => 'Hidden from us', 'tenant_ids' => [(string) $this->other->id]]);
        $this->actingAsOwner($this->tenant);
        $props = [];

        $this->get($this->commUrl($this->tenant, '/settings'))->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        expect($props['credentialRefs'])->toContain($account->reference())
            ->and($props['credentialLabels'])->toBe([$account->reference() => 'Operator Twilio'])
            ->and(json_encode($props))->not->toContain(self::TWILIO_TOKEN)->not->toContain(self::TWILIO_SID)
            ->not->toContain('Hidden from us');
    });
});

describe('WhatsApp account webhooks', function () {
    it('verifies a WhatsApp Cloud account handshake and signature with its own webhook secrets', function () {
        $account = $this->makeAccount([
            'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'name' => 'Meta', 'settings' => null,
            'secrets' => ['secret' => 'access-token-X', 'webhook_secret' => 'app-secret-X', 'verify_token' => 'verify-X'],
        ]);
        $this->inTenant($this->tenant, fn () => ChannelConnection::query()->create([
            'channel' => Channel::Whatsapp, 'provider' => 'whatsapp_cloud', 'is_enabled' => true,
            'settings' => ['phone_number_id' => '109876543210'],
            'credential_ref' => $account->reference(), 'webhook_secret_ref' => $account->reference(),
        ]));
        $url = $this->commUrl($this->tenant, '/webhooks/whatsapp_cloud');
        $body = (string) json_encode(['object' => 'whatsapp_business_account', 'entry' => []]);
        $post = fn (string $secret) => $this->call('POST', $url, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
        ], $body);

        $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-X&hub.challenge=4242')->assertOk()->assertSee('4242', false);
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=access-token-X&hub.challenge=4242')->assertForbidden();

        $post('app-secret-X')->assertOk();
        // The access token must not be accepted as the signing secret.
        $post('access-token-X')->assertUnauthorized();

        // Once the operator disables the account the workspace has no secret to verify against.
        $account->update(['is_enabled' => false]);
        $post('app-secret-X')->assertNotFound();
        $this->get($url.'?hub.mode=subscribe&hub.verify_token=verify-X&hub.challenge=4242')->assertNotFound();
    });
});

describe('sending through an account', function () {
    it('sends an SMS through a Twilio account, then fails honestly once the account is disabled', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1111'], 201)]);
        $account = $this->makeAccount();
        $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->save(Channel::Sms, [
            'provider' => 'twilio', 'is_enabled' => true, 'credential_ref' => $account->reference(),
        ]));

        $message = ($this->queue)();
        $stored = $this->inTenant($this->tenant, fn () => Message::query()->findOrFail($message->id));

        expect($stored->status)->toBe(MessageStatus::Sent)
            ->and($stored->provider)->toBe('twilio')
            ->and($stored->provider_message_id)->toBe('SM1111');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/Accounts/'.self::TWILIO_SID.'/Messages.json')
            && $request->header('Authorization')[0] === 'Basic '.base64_encode(self::TWILIO_SID.':'.self::TWILIO_TOKEN)
            && $request['To'] === '+8801711000000'
            && $request['From'] === '+15005550006'
            && $request['Body'] === 'Your order is on its way.');
        Http::assertSentCount(1);

        $account->update(['is_enabled' => false]);
        $second = ($this->queue)(['body' => 'Another message']);
        $second = $this->inTenant($this->tenant, fn () => Message::query()->findOrFail($second->id));

        expect($second->status)->toBe(MessageStatus::Failed)
            ->and($second->failure_code)->toBe('provider_not_configured');
        Http::assertSentCount(1);
    });

    it('fails a queued message at worker time when the account was disabled after queueing', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM2222'], 201)]);
        $account = $this->makeAccount();
        $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->save(Channel::Sms, [
            'provider' => 'twilio', 'is_enabled' => true, 'credential_ref' => $account->reference(),
        ]));
        Queue::fake();

        $message = ($this->queue)();
        expect($message->status)->toBe(MessageStatus::Queued);

        $account->update(['is_enabled' => false]);
        $this->inTenant($this->tenant, fn () => app(MessageDispatcher::class)->deliver($message->id));

        $stored = $this->inTenant($this->tenant, fn () => Message::query()->findOrFail($message->id));
        expect($stored->status)->toBe(MessageStatus::Failed)->and($stored->failure_code)->toBe('provider_not_configured');
        Http::assertNothingSent();
    });

    it('sends through an HTTP SMS gateway account and keeps its key out of the stored message', function () {
        Http::fake(['sms.gateway-example.com/*' => Http::response(['message_id' => 'GW-77', 'status' => 'queued'], 200)]);
        $account = $this->makeHttpSmsAccount();
        $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->save(Channel::Sms, [
            'provider' => 'http_sms', 'is_enabled' => true, 'credential_ref' => $account->reference(),
        ]));

        $message = ($this->queue)();
        $stored = $this->inTenant($this->tenant, fn () => Message::query()->findOrFail($message->id));

        expect($stored->status)->toBe(MessageStatus::Sent)
            ->and($stored->provider)->toBe('http_sms')
            ->and($stored->provider_message_id)->toBe('GW-77')
            ->and(json_encode($stored->getAttributes()))->not->toContain('gateway-api-key-SECRET-2');
        Http::assertSent(fn ($request) => $request->url() === 'https://sms.gateway-example.com/api/send'
            && $request->header('Authorization')[0] === 'Bearer gateway-api-key-SECRET-2'
            && $request['to'] === '+8801711000000');
    });

    it('does not let a workspace use an account the operator limited to another workspace', function () {
        Http::fake();
        $account = $this->makeAccount();
        $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->save(Channel::Sms, [
            'provider' => 'twilio', 'is_enabled' => true, 'credential_ref' => $account->reference(),
        ]));
        // The operator narrows the account to somebody else after the workspace already chose it.
        $account->update(['tenant_ids' => [(string) $this->other->id]]);

        $message = ($this->queue)();
        $message = $this->inTenant($this->tenant, fn () => Message::query()->findOrFail($message->id));

        expect($message->status)->toBe(MessageStatus::Failed)->and($message->failure_code)->toBe('provider_not_configured');
        Http::assertNothingSent();
    });

    it('refuses to save an account reference through the manager for a provider it does not belong to', function () {
        $account = $this->makeAccount();

        expect(fn () => $this->inTenant($this->tenant, fn () => app(ChannelConnections::class)->save(Channel::Sms, [
            'provider' => 'http_sms', 'credential_ref' => $account->reference(), 'settings' => [],
        ])))->toThrow(ValidationException::class);

        expect(ProviderAccount::query()->count())->toBe(1);
    });
});
