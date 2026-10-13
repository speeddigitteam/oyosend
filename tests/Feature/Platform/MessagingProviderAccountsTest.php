<?php

declare(strict_types=1);

use App\Modules\Communication\Models\ProviderAccount;
use App\Platform\Enums\PlatformRole;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithProviderAccounts;

/*
 * Super Admin > Messaging providers (ADR 0013).
 *
 * `ProviderAccount` is platform data, not tenant-owned: `assertTenantIsolated()` does not apply. The routes are
 * platform routes with a ULID of a platform record, so `assertNotFoundFromOtherTenant()` (workspace routes) does
 * not apply either; a missing id answers 404 and a workspace host has no such route at all.
 */

uses(InteractsWithProviderAccounts::class);

beforeEach(function () {
    $this->admin = $this->createPlatformUser();
    $this->acme = $this->createTenant('acme-msg', ['name' => 'Acme Messaging']);
    $this->globex = $this->createTenant('globex-msg', ['name' => 'Globex Messaging']);
    $this->secretValues = [
        'smtp-password-SECRET-3', self::TWILIO_TOKEN, 'gateway-api-key-SECRET-2',
        'wa-access-token-SECRET-4', 'wa-app-secret-SECRET-5', 'wa-verify-SECRET-6',
    ];
});

describe('access', function () {
    it('keeps the routes behind the platform guard and the settings permission', function () {
        $account = $this->makeAccount();

        $this->get($this->platformUrl())->assertRedirect();
        $this->post($this->platformUrl(), $this->accountInput('twilio'))->assertRedirect();

        $support = $this->createPlatformUser(PlatformRole::SupportAdmin);
        $this->actingAsPlatformUser($support);
        $this->get($this->platformUrl())->assertForbidden();
        $this->post($this->platformUrl(), $this->accountInput('twilio'))->assertForbidden();
        $this->put($this->platformUrl('/messaging/'.$account->id), ['name' => 'x'])->assertForbidden();
        $this->delete($this->platformUrl('/messaging/'.$account->id))->assertForbidden();
        $this->post($this->platformUrl('/messaging/'.$account->id.'/test'), ['to' => '+8801711000000'])->assertForbidden();

        expect(ProviderAccount::query()->count())->toBe(1);
    });

    it('does not exist on a workspace host, even for its owner', function () {
        $account = $this->makeAccount();
        $this->actingAsOwner($this->acme);

        $this->get($this->tenantUrl($this->acme, '/messaging'))->assertNotFound();
        $this->post($this->tenantUrl($this->acme, '/messaging'), $this->accountInput('twilio'))->assertNotFound();
        $this->put($this->tenantUrl($this->acme, '/messaging/'.$account->id), ['name' => 'x'])->assertNotFound();
        $this->delete($this->tenantUrl($this->acme, '/messaging/'.$account->id))->assertNotFound();
    });

    it('answers 404 for an account that does not exist', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/01HZZZZZZZZZZZZZZZZZZZZZZZ'), ['name' => 'x'])->assertNotFound();
        $this->delete($this->platformUrl('/messaging/01HZZZZZZZZZZZZZZZZZZZZZZZ'))->assertNotFound();
        $this->post($this->platformUrl('/messaging/01HZZZZZZZZZZZZZZZZZZZZZZZ/test'), ['to' => '+8801711000000'])->assertNotFound();
    });
});

describe('the page', function () {
    it('lists accounts, the real providers and the workspaces, with no test double and no secret', function () {
        $this->makeAccount(['name' => 'Twilio A']);
        $this->actingAsPlatformUser($this->admin);
        $props = [];

        $this->get($this->platformUrl())->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $page->component('Platform/Pages/Messaging/Index');
            $props = $page->toArray()['props'];
        });

        $providers = collect($props['providers']);

        expect($props['accounts'])->toHaveCount(1)
            ->and($props['accounts'][0]['name'])->toBe('Twilio A')
            ->and($props['accounts'][0]['secretsSet'])->toBe(['secret'])
            ->and($providers->pluck('key')->sort()->values()->all())->toBe(['http_sms', 'smtp', 'twilio', 'whatsapp_cloud'])
            ->and(collect($props['workspaces'])->pluck('name')->all())->toContain('Acme Messaging', 'Globex Messaging')
            ->and(json_encode($props))->not->toContain(self::TWILIO_TOKEN);
    });
});

describe('creating accounts', function () {
    it('creates an SMTP, a Twilio, an HTTP SMS and a WhatsApp Cloud account', function (string $provider) {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput($provider))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $account = ProviderAccount::query()->sole();
        expect($account->provider)->toBe($provider)
            ->and($account->is_enabled)->toBeTrue()
            ->and($account->tenant_ids)->toBeNull()
            ->and($account->secrets['secret'])->toBeString()->not->toBe('')
            ->and($account->reference())->toBe('acct_'.strtolower($account->id));
    })->with(['smtp', 'twilio', 'http_sms', 'whatsapp_cloud']);

    it('stores integer settings as integers and keeps only declared fields', function () {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput('smtp');
        $input['settings']['port'] = '2525';
        $input['settings']['unknown_field'] = 'dropped';

        $this->post($this->platformUrl(), $input)->assertSessionHasNoErrors();

        $settings = ProviderAccount::query()->sole()->settings;
        expect($settings['port'])->toBe(2525)->and($settings)->not->toHaveKey('unknown_field');
    });

    it('limits an account to the workspaces named', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput('twilio', ['tenant_ids' => [(string) $this->acme->id]]))
            ->assertSessionHasNoErrors();

        expect(ProviderAccount::query()->sole()->tenant_ids)->toBe([(string) $this->acme->id]);
    });

    it('refuses a missing required setting or secret', function (string $provider, string $remove, string $errorKey) {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput($provider);

        if (str_starts_with($remove, 'secrets.')) {
            unset($input['secrets'][substr($remove, 8)]);
        } else {
            unset($input['settings'][substr($remove, 9)]);
        }

        $this->post($this->platformUrl(), $input)->assertSessionHasErrors($errorKey);
        expect(ProviderAccount::query()->count())->toBe(0);
    })->with([
        'smtp host' => ['smtp', 'settings.host', 'settings.host'],
        'smtp password' => ['smtp', 'secrets.secret', 'secrets.secret'],
        'smtp from' => ['smtp', 'settings.from_address', 'settings.from_address'],
        'twilio sid' => ['twilio', 'settings.account_sid', 'settings.account_sid'],
        'twilio sender' => ['twilio', 'settings.from', 'settings.from'],
        'http endpoint' => ['http_sms', 'settings.endpoint', 'settings.endpoint'],
        'http key' => ['http_sms', 'secrets.secret', 'secrets.secret'],
        'whatsapp token' => ['whatsapp_cloud', 'secrets.secret', 'secrets.secret'],
    ]);

    it('validates the SMTP from address, port and encryption', function () {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput('smtp');

        $this->post($this->platformUrl(), [...$input, 'settings' => [...$input['settings'], 'from_address' => 'not-an-email']])
            ->assertSessionHasErrors('settings.from_address');
        $this->post($this->platformUrl(), [...$input, 'settings' => [...$input['settings'], 'port' => 70000]])
            ->assertSessionHasErrors('settings.port');
        $this->post($this->platformUrl(), [...$input, 'settings' => [...$input['settings'], 'encryption' => 'none']])
            ->assertSessionHasErrors('settings.encryption');
    });

    it('validates the Twilio account sid', function (string $sid) {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput('twilio');
        $input['settings']['account_sid'] = $sid;

        $this->post($this->platformUrl(), $input)->assertSessionHasErrors('settings.account_sid');
    })->with(['too short' => 'AC123', 'wrong prefix' => 'XX0123456789abcdef0123456789abcdef', 'not hex' => 'ACzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz']);

    it('refuses a plain http gateway endpoint unless the platform allows it', function () {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput('http_sms');
        $input['settings']['endpoint'] = 'http://sms.gateway-example.com/api/send';

        $this->post($this->platformUrl(), $input)->assertSessionHasErrors('settings.endpoint');
        expect(ProviderAccount::query()->count())->toBe(0);

        config(['communication.sms.allow_insecure_http' => true]);
        $this->post($this->platformUrl(), $input)->assertSessionHasNoErrors();
        expect(ProviderAccount::query()->count())->toBe(1);
    });

    it('refuses private, local, IP-literal and credential-bearing gateway hosts', function (string $endpoint) {
        $this->actingAsPlatformUser($this->admin);
        config(['communication.sms.allow_insecure_http' => true]);
        $input = $this->accountInput('http_sms');
        $input['settings']['endpoint'] = $endpoint;

        $this->post($this->platformUrl(), $input)->assertSessionHasErrors('settings.endpoint');
        expect(ProviderAccount::query()->count())->toBe(0);
    })->with([
        'private ipv4' => 'https://192.168.1.5/send',
        'loopback ipv4' => 'https://127.0.0.1/send',
        'metadata ip' => 'http://169.254.169.254/latest',
        'ipv6 literal' => 'https://[::1]/send',
        'localhost' => 'https://localhost/send',
        'dot local' => 'https://sms.local/send',
        'dot internal' => 'https://gateway.internal/send',
        'single label' => 'https://intranet/send',
        'embedded credentials' => 'https://user:pass@sms.gateway-example.com/send',
        'not a url' => 'sms.gateway-example.com',
        'ftp' => 'ftp://sms.gateway-example.com/send',
    ]);

    it('validates the gateway request description', function () {
        $this->actingAsPlatformUser($this->admin);
        $input = $this->accountInput('http_sms');

        $badParam = $input;
        $badParam['settings']['to_param'] = 'to number!';
        $this->post($this->platformUrl(), $badParam)->assertSessionHasErrors('settings.to_param');

        $noName = $input;
        $noName['settings']['auth_mode'] = 'header';
        $this->post($this->platformUrl(), $noName)->assertSessionHasErrors('settings.auth_name');

        $senderWithoutValue = $input;
        $senderWithoutValue['settings']['sender_param'] = 'from';
        $this->post($this->platformUrl(), $senderWithoutValue)->assertSessionHasErrors('settings.sender_value');

        $badExtra = $input;
        $badExtra['settings']['extra_params'] = 'format=json&oops';
        $this->post($this->platformUrl(), $badExtra)->assertSessionHasErrors('settings.extra_params');

        $badMode = $input;
        $badMode['settings']['auth_mode'] = 'cookie';
        $this->post($this->platformUrl(), $badMode)->assertSessionHasErrors('settings.auth_mode');

        $good = $input;
        $good['settings'] = [...$good['settings'], 'auth_mode' => 'header', 'auth_name' => 'X-Api-Key', 'sender_param' => 'from', 'sender_value' => 'ACME', 'extra_params' => 'format=json&type=text'];
        $this->post($this->platformUrl(), $good)->assertSessionHasNoErrors();
    });

    it('refuses a duplicate name within a channel but allows it across channels', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput('twilio', ['name' => 'Shared name']))->assertSessionHasNoErrors();
        $this->post($this->platformUrl(), $this->accountInput('http_sms', ['name' => 'Shared name']))->assertSessionHasErrors('name');
        $this->post($this->platformUrl(), $this->accountInput('smtp', ['name' => 'Shared name']))->assertSessionHasNoErrors();

        expect(ProviderAccount::query()->count())->toBe(2);
    });

    it('refuses an unknown provider, a test double and a provider of another channel', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput('twilio', ['provider' => 'mystery']))->assertSessionHasErrors('provider');
        $this->post($this->platformUrl(), $this->accountInput('twilio', ['provider' => 'fake_sms']))->assertSessionHasErrors('provider');
        $this->post($this->platformUrl(), $this->accountInput('twilio', ['channel' => 'email']))->assertSessionHasErrors('provider');
        $this->post($this->platformUrl(), $this->accountInput('twilio', ['channel' => 'fax']))->assertSessionHasErrors('provider');
        $this->post($this->platformUrl(), $this->accountInput('twilio', ['provider' => 'mail', 'channel' => 'email']))->assertSessionHasErrors('provider');

        expect(ProviderAccount::query()->count())->toBe(0);
    });

    it('refuses a workspace that does not exist, or an empty selection', function (mixed $ids) {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput('twilio', ['tenant_ids' => $ids]))->assertSessionHasErrors('tenant_ids');
        if ($ids !== []) {
            $this->post($this->platformUrl(), $this->accountInput('twilio', ['tenant_ids' => [(string) $this->acme->id, ...(array) $ids]]))
                ->assertSessionHasErrors('tenant_ids');
        }

        expect(ProviderAccount::query()->count())->toBe(0);
    })->with([
        'unknown' => [['01HZZZZZZZZZZZZZZZZZZZZZZZ']],
        'empty list' => [[]],
    ]);

    it('refuses an over-long name and over-long secrets', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl(), $this->accountInput('twilio', ['name' => str_repeat('n', 81)]))->assertSessionHasErrors('name');
        $this->post($this->platformUrl(), $this->accountInput('twilio', ['secrets' => ['secret' => str_repeat('s', 4097)]]))->assertSessionHasErrors('secrets.secret');
    });

    it('records an audit entry that names the account but carries no secret', function () {
        $this->actingAsPlatformUser($this->admin);
        $this->post($this->platformUrl(), $this->accountInput('http_sms'));

        $entry = $this->auditTrail(null, 'platform.messaging.account_created')->sole();

        expect($entry->properties)->toHaveKeys(['account', 'channel', 'provider', 'name'])
            ->and($entry->properties)->not->toHaveKeys(['secret', 'secrets', 'settings']);
    });
});

describe('updating and deleting', function () {
    it('keeps the stored secret when the secret is left empty', function () {
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/'.$account->id), [
            'name' => 'Renamed',
            'is_enabled' => false,
            'settings' => ['account_sid' => self::TWILIO_SID, 'from' => 'MG0123456789abcdef0123456789abcdef'],
            'secrets' => ['secret' => ''],
            'tenant_ids' => [(string) $this->acme->id],
        ])->assertRedirect()->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');

        $fresh = $account->fresh();
        expect($fresh->name)->toBe('Renamed')
            ->and($fresh->is_enabled)->toBeFalse()
            ->and($fresh->settings['from'])->toStartWith('MG')
            ->and($fresh->secrets['secret'])->toBe(self::TWILIO_TOKEN)
            ->and($fresh->tenant_ids)->toBe([(string) $this->acme->id]);

        $entry = $this->auditTrail(null, 'platform.messaging.account_updated')->sole();
        expect($entry->properties['secrets_replaced'])->toBe([]);
    });

    it('keeps the stored secret when the field is not sent at all', function () {
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/'.$account->id), [
            'name' => $account->name,
            'settings' => $account->settings,
        ])->assertSessionHasNoErrors();

        expect($account->fresh()->secrets['secret'])->toBe(self::TWILIO_TOKEN);
    });

    it('replaces the secret when a new one is sent and audits only its name', function () {
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/'.$account->id), [
            'name' => $account->name,
            'settings' => $account->settings,
            'secrets' => ['secret' => 'brand-new-token-SECRET-7'],
        ])->assertSessionHasNoErrors();

        expect($account->fresh()->secrets['secret'])->toBe('brand-new-token-SECRET-7');

        $entry = $this->auditTrail(null, 'platform.messaging.account_updated')->sole();
        expect($entry->properties['secrets_replaced'])->toBe(['secret'])
            ->and(json_encode($entry->properties))->not->toContain('brand-new-token-SECRET-7')->not->toContain(self::TWILIO_TOKEN);
    });

    it('cannot change the channel or provider of an existing account', function () {
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/'.$account->id), [
            'channel' => 'email', 'provider' => 'smtp', 'name' => $account->name, 'settings' => $account->settings,
        ])->assertSessionHasNoErrors();

        expect($account->fresh()->provider)->toBe('twilio')->and($account->fresh()->channel->value)->toBe('sms');
    });

    it('validates on update like on create, and refuses a name another account holds', function () {
        $first = $this->makeAccount(['name' => 'First']);
        $second = $this->makeAccount(['name' => 'Second']);
        $this->actingAsPlatformUser($this->admin);

        $this->put($this->platformUrl('/messaging/'.$second->id), ['name' => 'First', 'settings' => $second->settings])
            ->assertSessionHasErrors('name');
        $this->put($this->platformUrl('/messaging/'.$second->id), ['name' => 'Second', 'settings' => ['account_sid' => 'bad', 'from' => '+1']])
            ->assertSessionHasErrors('settings.account_sid');
        // Keeping its own name is not a duplicate.
        $this->put($this->platformUrl('/messaging/'.$first->id), ['name' => 'First', 'settings' => $first->settings])
            ->assertSessionHasNoErrors();
    });

    it('deletes an account and audits it', function () {
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->delete($this->platformUrl('/messaging/'.$account->id))->assertRedirect()->assertInertiaFlash('toast.type', 'success');

        expect(ProviderAccount::query()->count())->toBe(0)
            ->and($this->auditTrail(null, 'platform.messaging.account_deleted'))->toHaveCount(1);
    });
});

describe('secrets never leave', function () {
    it('appears in no page prop, flash, session, audit row or serialization after a full lifecycle', function () {
        $this->actingAsPlatformUser($this->admin);
        $inputs = array_map(fn (string $provider): array => $this->accountInput($provider), ['smtp', 'twilio', 'http_sms', 'whatsapp_cloud']);

        foreach ($inputs as $input) {
            $this->post($this->platformUrl(), $input)->assertSessionHasNoErrors();
        }

        $account = ProviderAccount::query()->where('provider', 'twilio')->sole();
        $this->put($this->platformUrl('/messaging/'.$account->id), [
            'name' => 'Twilio main', 'settings' => $account->settings, 'secrets' => ['secret' => 'rotated-token-SECRET-8'],
        ])->assertSessionHasNoErrors();

        // A validation failure echoes input back as old input: it must not carry secrets either.
        $failing = $this->accountInput('smtp', ['name' => 'Another']);
        $failing['settings']['from_address'] = 'broken';
        $this->post($this->platformUrl(), $failing)->assertSessionHasErrors('settings.from_address');

        $props = [];
        $this->get($this->platformUrl())->assertInertia(function (AssertableInertia $page) use (&$props): void {
            $props = $page->toArray()['props'];
        });

        $everything = [
            'props' => json_encode($props),
            'session' => json_encode(session()->all()),
            'audit' => json_encode(
                $this->auditTrail(null)->map(fn ($row) => ['event' => $row->event, 'properties' => $row->properties, 'subject' => $row->subject_label])->all(),
            ),
            'toArray' => json_encode(ProviderAccount::query()->get()->map->toArray()->all()),
            'toJson' => ProviderAccount::query()->get()->toJson(),
        ];

        foreach ([...$this->secretValues, 'rotated-token-SECRET-8'] as $secret) {
            foreach ($everything as $where => $dump) {
                expect($dump)->not->toContain($secret, "{$where} leaked a secret");
            }
        }

        expect($everything['toArray'])->not->toContain('"secrets"');
    });

    it('hides the secrets attribute and stores it encrypted', function () {
        $account = $this->makeAccount(['secrets' => ['secret' => self::TWILIO_TOKEN, 'webhook_secret' => 'hook-SECRET-9']]);

        expect($account->toArray())->not->toHaveKey('secrets')
            ->and($account->getHidden())->toContain('secrets');

        $raw = (string) $account->getConnection()->table($account->getTable())->where('id', $account->id)->value('secrets');

        expect($raw)->not->toBe('')
            ->and($raw)->not->toContain(self::TWILIO_TOKEN)->not->toContain('hook-SECRET-9')
            ->and(json_decode($raw, true))->toBeNull();

        $loaded = ProviderAccount::query()->findOrFail($account->id);
        expect($loaded->secrets)->toBe(['secret' => self::TWILIO_TOKEN, 'webhook_secret' => 'hook-SECRET-9']);
    });
});

describe('sending a test message', function () {
    it('reports success and audits only the outcome', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM0001'], 201)]);
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl('/messaging/'.$account->id.'/test'), ['to' => '+880 1711-000000'])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        Http::assertSent(fn ($request) => $request['To'] === '+8801711000000' && $request->hasHeader('Authorization'));

        $entry = $this->auditTrail(null, 'platform.messaging.account_tested')->sole();
        expect($entry->properties)->toEqual(['account' => $account->id, 'channel' => 'sms', 'outcome' => 'accepted', 'code' => null])
            ->and(json_encode($entry->properties))->not->toContain('8801711000000')->not->toContain(self::TWILIO_TOKEN);
    });

    it('reports a failure with the provider code, not its text or the secret', function () {
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'Authenticate '.self::TWILIO_TOKEN], 401)]);
        $account = $this->makeAccount();
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl('/messaging/'.$account->id.'/test'), ['to' => '+8801711000000'])
            ->assertRedirect()
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'The test message was not sent (provider_credentials_rejected).');

        $entry = $this->auditTrail(null, 'platform.messaging.account_tested')->sole();
        expect($entry->properties['outcome'])->toBe('rejected')
            ->and($entry->properties['code'])->toBe('provider_credentials_rejected')
            ->and(json_encode(session()->all()))->not->toContain(self::TWILIO_TOKEN);
    });

    it('validates the recipient and refuses providers that cannot send a test', function () {
        Http::fake();
        $account = $this->makeAccount();
        $whatsapp = $this->makeAccount([
            'channel' => 'whatsapp', 'provider' => 'whatsapp_cloud', 'name' => 'WA', 'settings' => null, 'secrets' => ['secret' => 'wa-token'],
        ]);
        $this->actingAsPlatformUser($this->admin);

        $this->post($this->platformUrl('/messaging/'.$account->id.'/test'), ['to' => 'not-a-number'])->assertSessionHasErrors('to');
        $this->post($this->platformUrl('/messaging/'.$account->id.'/test'), [])->assertSessionHasErrors('to');
        $this->post($this->platformUrl('/messaging/'.$whatsapp->id.'/test'), ['to' => '+8801711000000'])->assertSessionHasErrors('to');

        Http::assertNothingSent();
        expect($this->auditTrail(null, 'platform.messaging.account_tested'))->toHaveCount(0);
    });
});
