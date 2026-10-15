<?php

declare(strict_types=1);

use App\Core\Settings\IntegrationCredential;
use App\Core\Settings\IntegrationCredentials;
use App\Platform\Enums\PlatformRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPlatform;

/*
 * Super Admin > Integrations > Social and Meta Ads app credentials
 * (docs/adr/0029-social-and-meta-ads-panel-credentials.md).
 *
 * `IntegrationCredential` is platform data, not tenant-owned, so the tenant-isolation helpers do not
 * apply; the routes are platform routes behind the platform guard and the settings permission.
 */

uses(InteractsWithPlatform::class);

function integrationUrl(string $group = '', string $name = ''): string
{
    if ($group === '') {
        return 'http://admin.localhost/settings/integrations';
    }

    return 'http://admin.localhost/settings/integrations/'.$group.'/'.$name;
}

/** The live config value after the operator overlay is applied with a fresh resolver. */
function overlaidConfig(string $key): mixed
{
    app()->forgetInstance(IntegrationCredentials::class);
    app(IntegrationCredentials::class)->apply(app('config'));

    return config($key);
}

beforeEach(function () {
    $this->admin = $this->createPlatformUser();
    // The environment fallback for Facebook, so panel-first is observable against it.
    config([
        'social.platforms.facebook.client_id' => 'env-fb-id',
        'social.platforms.facebook.client_secret' => 'env-fb-secret',
        'social.lead_ads.webhook_verify_token' => 'env-verify',
    ]);
});

describe('access', function () {
    it('keeps the routes behind the platform guard and the settings permission', function () {
        $this->put(integrationUrl('social', 'facebook'), ['enabled' => true])->assertRedirect();

        $this->actingAsPlatformUser($this->createPlatformUser(PlatformRole::SupportAdmin));
        $this->put(integrationUrl('social', 'facebook'), ['enabled' => true])->assertForbidden();
        $this->delete(integrationUrl('social', 'facebook'))->assertForbidden();

        expect(IntegrationCredential::query()->count())->toBe(0);
    });

    it('answers 404 for an integration that is not managed', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'myspace'), ['enabled' => true])->assertNotFound();
        $this->put(integrationUrl('billing', 'facebook'), ['enabled' => true])->assertNotFound();
        $this->delete(integrationUrl('social', 'myspace'))->assertNotFound();
    });
});

describe('saving credentials', function () {
    it('stores the secrets encrypted, shows the public id back and never returns a secret to the page', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => true,
            'public' => ['client_id' => 'panel-fb-id'],
            'secrets' => ['client_secret' => 'panel-fb-secret', 'webhook_verify_token' => 'panel-verify'],
        ])->assertRedirect();

        $row = IntegrationCredential::query()->where('group', 'social')->where('name', 'facebook')->first();
        expect($row)->not->toBeNull()
            ->and($row->is_enabled)->toBeTrue()
            ->and($row->settings)->toBe(['client_id' => 'panel-fb-id'])
            ->and($row->secrets)->toBe(['client_secret' => 'panel-fb-secret', 'webhook_verify_token' => 'panel-verify']);

        // The row stores the secrets encrypted, not as readable text.
        $raw = (string) DB::connection($row->getConnectionName())
            ->table('integration_credentials')->where('group', 'social')->where('name', 'facebook')->value('secrets');
        expect($raw)->not->toContain('panel-fb-secret')
            ->and($raw)->not->toContain('panel-verify');

        // The page shows it is managed and the public id, and carries no secret value.
        $this->get(integrationUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations.0.group', 'social')
            ->where('integrations.0.name', 'facebook')
            ->where('integrations.0.managed', true)
            ->where('integrations.0.source', 'panel')
            ->where('integrations.0.publicValues.client_id', 'panel-fb-id')
            ->where('integrations.0.storedSecretFields', ['client_secret', 'webhook_verify_token'])
            ->missing('integrations.0.secrets'));
    });

    it('overlays the panel secret onto the live config, over the environment', function () {
        $this->actingAsPlatformUser($this->admin);

        expect(overlaidConfig('social.platforms.facebook.client_secret'))->toBe('env-fb-secret');

        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => true,
            'public' => ['client_id' => 'panel-fb-id'],
            'secrets' => ['client_secret' => 'panel-fb-secret', 'webhook_verify_token' => 'panel-verify'],
        ])->assertRedirect();

        expect(overlaidConfig('social.platforms.facebook.client_secret'))->toBe('panel-fb-secret')
            ->and(overlaidConfig('social.lead_ads.webhook_verify_token'))->toBe('panel-verify')
            ->and(overlaidConfig('social.platforms.facebook.client_id'))->toBe('panel-fb-id');
    });

    it('falls back to the environment when there is no row', function () {
        $this->actingAsPlatformUser($this->admin);

        expect(overlaidConfig('social.platforms.facebook.client_id'))->toBe('env-fb-id')
            ->and(overlaidConfig('social.platforms.facebook.client_secret'))->toBe('env-fb-secret');
    });

    it('does not overlay a disabled row', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => false,
            'public' => ['client_id' => 'panel-fb-id'],
            'secrets' => ['client_secret' => 'panel-fb-secret'],
        ])->assertRedirect();

        // The environment stands: a disabled row overlays nothing, secret or public.
        expect(overlaidConfig('social.platforms.facebook.client_secret'))->toBe('env-fb-secret')
            ->and(overlaidConfig('social.platforms.facebook.client_id'))->toBe('env-fb-id');
    });

    it('keeps a stored secret when its field is left blank', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => true,
            'public' => ['client_id' => 'panel-fb-id'],
            'secrets' => ['client_secret' => 'panel-fb-secret', 'webhook_verify_token' => 'panel-verify'],
        ])->assertRedirect();

        // Change only the public id; leave the secrets blank.
        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => true,
            'public' => ['client_id' => 'panel-fb-id-2'],
            'secrets' => ['client_secret' => '', 'webhook_verify_token' => ''],
        ])->assertRedirect();

        $row = IntegrationCredential::query()->where('group', 'social')->where('name', 'facebook')->first();
        expect($row->secrets)->toBe(['client_secret' => 'panel-fb-secret', 'webhook_verify_token' => 'panel-verify'])
            ->and($row->settings)->toBe(['client_id' => 'panel-fb-id-2']);
    });
});

describe('clearing credentials', function () {
    it('forgets the panel credentials and falls back to the environment', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'facebook'), [
            'enabled' => true,
            'public' => ['client_id' => 'panel-fb-id'],
            'secrets' => ['client_secret' => 'panel-fb-secret'],
        ])->assertRedirect();

        $this->delete(integrationUrl('social', 'facebook'))->assertRedirect();

        expect(IntegrationCredential::query()->count())->toBe(0)
            ->and(overlaidConfig('social.platforms.facebook.client_secret'))->toBe('env-fb-secret');
    });
});

it('overlays the Meta Ads app credentials the same way', function () {
    $this->actingAsPlatformUser($this->admin);
    config(['meta_ads.app_id' => 'env-app', 'meta_ads.app_secret' => 'env-secret']);

    $this->put(integrationUrl('meta_ads', 'app'), [
        'enabled' => true,
        'public' => ['app_id' => 'panel-app'],
        'secrets' => ['app_secret' => 'panel-secret'],
    ])->assertRedirect();

    expect(overlaidConfig('meta_ads.app_id'))->toBe('panel-app')
        ->and(overlaidConfig('meta_ads.app_secret'))->toBe('panel-secret');
});

describe('the platforms of ADR 0033', function () {
    it('overlays the TikTok, YouTube and Pinterest app credentials over the environment', function (string $name, string $publicField, string $idKey, string $secretKey) {
        $this->actingAsPlatformUser($this->admin);
        config([$idKey => 'env-id', $secretKey => 'env-secret']);

        expect(overlaidConfig($idKey))->toBe('env-id')
            ->and(overlaidConfig($secretKey))->toBe('env-secret');

        $this->put(integrationUrl('social', $name), [
            'enabled' => true,
            'public' => [$publicField => 'panel-id'],
            'secrets' => ['client_secret' => 'panel-secret'],
        ])->assertRedirect();

        $row = IntegrationCredential::query()->where('group', 'social')->where('name', $name)->first();
        $raw = (string) DB::connection($row->getConnectionName())
            ->table('integration_credentials')->where('group', 'social')->where('name', $name)->value('secrets');

        expect($raw)->not->toContain('panel-secret')
            ->and(overlaidConfig($idKey))->toBe('panel-id')
            ->and(overlaidConfig($secretKey))->toBe('panel-secret');

        // The page lists it with its public id and never a secret.
        $this->get(integrationUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['name'] === $name
                && $row['source'] === 'panel'
                && $row['publicValues'] === [$publicField => 'panel-id']
                && $row['storedSecretFields'] === ['client_secret']
                && ! array_key_exists('secrets', $row))));
    })->with([
        'tiktok (client key into client_id)' => ['tiktok', 'client_key', 'social.platforms.tiktok.client_id', 'social.platforms.tiktok.client_secret'],
        'youtube' => ['youtube', 'client_id', 'social.platforms.youtube.client_id', 'social.platforms.youtube.client_secret'],
        'pinterest' => ['pinterest', 'client_id', 'social.platforms.pinterest.client_id', 'social.platforms.pinterest.client_secret'],
    ]);

    it('labels Instagram as using the Meta app, never as missing credentials of its own', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->get(integrationUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['name'] === 'instagram'
                && $row['source'] === 'switch'
                && $row['secretFields'] === []
                && $row['publicFields'] === [])));
    });

    it('switches Instagram publishing from the panel, with the environment as the fallback', function () {
        $this->actingAsPlatformUser($this->admin);
        config(['social.platforms.instagram.publishing' => false]);

        expect(overlaidConfig('social.platforms.instagram.publishing'))->toBeFalse();

        $this->get(integrationUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('integrations', fn ($rows): bool => collect($rows)->contains(fn ($row): bool => $row['name'] === 'instagram'
                && $row['flagFields'] === ['publishing']
                && $row['flagValues'] === ['publishing' => false]
                && $row['secretFields'] === [] && $row['publicFields'] === [])));

        $this->put(integrationUrl('social', 'instagram'), ['enabled' => true, 'flags' => ['publishing' => true]])->assertRedirect();

        expect(IntegrationCredential::query()->where('name', 'instagram')->first()->settings)->toBe(['publishing' => true])
            ->and(overlaidConfig('social.platforms.instagram.publishing'))->toBeTrue();

        // Off in the panel beats on in the environment: a stored switch always overlays.
        config(['social.platforms.instagram.publishing' => true]);
        $this->put(integrationUrl('social', 'instagram'), ['enabled' => true, 'flags' => ['publishing' => false]])->assertRedirect();
        expect(overlaidConfig('social.platforms.instagram.publishing'))->toBeFalse();

        // Clearing it falls back to the environment (as the next boot reads it).
        $this->delete(integrationUrl('social', 'instagram'))->assertRedirect();
        config(['social.platforms.instagram.publishing' => true]);
        expect(overlaidConfig('social.platforms.instagram.publishing'))->toBeTrue();
    });

    it('refuses a switch value that is not a boolean', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(integrationUrl('social', 'instagram'), ['enabled' => true, 'flags' => ['publishing' => 'sometimes']])
            ->assertSessionHasErrors('flags.publishing');

        expect(IntegrationCredential::query()->count())->toBe(0);
    });
});
