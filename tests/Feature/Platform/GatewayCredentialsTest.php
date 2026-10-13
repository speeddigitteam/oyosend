<?php

declare(strict_types=1);

use App\Core\Billing\Gateways\GatewayCredentials;
use App\Core\Billing\Gateways\GatewayRegistry;
use App\Core\Billing\Models\PaymentGatewayAccount;
use App\Platform\Enums\PlatformRole;
use Inertia\Testing\AssertableInertia;
use Tests\Support\InteractsWithPlatform;

/*
 * Super Admin > Settings > payment gateway credentials (ADR 0028).
 *
 * `PaymentGatewayAccount` is platform data, not tenant-owned, so the tenant-isolation helpers do not
 * apply; the routes are platform routes behind the platform guard and the settings permission.
 */

uses(InteractsWithPlatform::class);

function gatewayUrl(string $gateway = ''): string
{
    return 'http://admin.localhost/settings'.($gateway === '' ? '' : '/gateways/'.$gateway);
}

/** A fresh resolver, past the per-request memo and the registry singleton. */
function resolveGateway(string $gateway): array
{
    app()->forgetInstance(GatewayCredentials::class);

    return app(GatewayCredentials::class)->for($gateway);
}

beforeEach(function () {
    $this->admin = $this->createPlatformUser();
    // The environment fallback for sslcommerz, so panel-first is observable against it.
    config([
        'platform.gateways.sslcommerz.store_id' => 'env-store',
        'platform.gateways.sslcommerz.store_password' => 'env-pass',
        'platform.gateways.sslcommerz.sandbox' => true,
    ]);
});

describe('access', function () {
    it('keeps the gateway routes behind the platform guard and the settings permission', function () {
        $this->put(gatewayUrl('sslcommerz'), ['sandbox' => true, 'enabled' => true])->assertRedirect();

        $this->actingAsPlatformUser($this->createPlatformUser(PlatformRole::SupportAdmin));
        $this->put(gatewayUrl('sslcommerz'), ['sandbox' => true, 'enabled' => true])->assertForbidden();
        $this->delete(gatewayUrl('sslcommerz'))->assertForbidden();

        expect(PaymentGatewayAccount::query()->count())->toBe(0);
    });

    it('answers 404 for a gateway that is not in this build', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(gatewayUrl('stripe'), ['sandbox' => true, 'enabled' => true])->assertNotFound();
        $this->delete(gatewayUrl('stripe'))->assertNotFound();
    });
});

describe('saving credentials', function () {
    it('stores the secrets encrypted, enables the gateway and never returns a secret to the page', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(gatewayUrl('sslcommerz'), [
            'sandbox' => false,
            'enabled' => true,
            'secrets' => ['store_id' => 'panel-store', 'store_password' => 'panel-pass'],
        ])->assertRedirect();

        $account = PaymentGatewayAccount::query()->firstWhere('gateway', 'sslcommerz');
        expect($account)->not->toBeNull()
            ->and($account->is_enabled)->toBeTrue()
            ->and($account->settings['sandbox'])->toBeFalse()
            ->and($account->secrets)->toBe(['store_id' => 'panel-store', 'store_password' => 'panel-pass']);

        // The row stores the secrets encrypted, not as readable text.
        $raw = (string) DB::connection($account->getConnectionName())
            ->table('payment_gateway_accounts')->where('gateway', 'sslcommerz')->value('secrets');
        expect($raw)->not->toContain('panel-pass');

        // The page learns it is managed from the panel, and carries no secret value.
        $this->get(gatewayUrl())->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gateways.0.key', 'sslcommerz')
            ->where('gateways.0.managed', true)
            ->where('gateways.0.source', 'panel')
            ->where('gateways.0.sandbox', false)
            ->where('gateways.0.storedFields', ['store_id', 'store_password'])
            ->missing('gateways.0.secrets'));
    });

    it('prefers the panel credentials over the environment', function () {
        $this->actingAsPlatformUser($this->admin);

        expect(resolveGateway('sslcommerz')['secrets'])->toBe(['store_id' => 'env-store', 'store_password' => 'env-pass']);

        $this->put(gatewayUrl('sslcommerz'), [
            'sandbox' => true,
            'enabled' => true,
            'secrets' => ['store_id' => 'panel-store', 'store_password' => 'panel-pass'],
        ])->assertRedirect();

        expect(resolveGateway('sslcommerz')['secrets'])->toBe(['store_id' => 'panel-store', 'store_password' => 'panel-pass']);
    });

    it('offers nothing while the gateway is disabled', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(gatewayUrl('sslcommerz'), [
            'sandbox' => true,
            'enabled' => false,
            'secrets' => ['store_id' => 'panel-store', 'store_password' => 'panel-pass'],
        ])->assertRedirect();

        expect(resolveGateway('sslcommerz')['secrets'])->toBe([]);
    });

    it('keeps a stored secret when its field is left blank', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(gatewayUrl('sslcommerz'), [
            'sandbox' => true,
            'enabled' => true,
            'secrets' => ['store_id' => 'panel-store', 'store_password' => 'panel-pass'],
        ])->assertRedirect();

        // Change only the sandbox flag; leave both secrets blank.
        $this->put(gatewayUrl('sslcommerz'), ['sandbox' => false, 'enabled' => true, 'secrets' => ['store_id' => '', 'store_password' => '']])
            ->assertRedirect();

        $account = PaymentGatewayAccount::query()->firstWhere('gateway', 'sslcommerz');
        expect($account->secrets)->toBe(['store_id' => 'panel-store', 'store_password' => 'panel-pass'])
            ->and($account->settings['sandbox'])->toBeFalse();
    });
});

describe('clearing credentials', function () {
    it('forgets the panel credentials and falls back to the environment', function () {
        $this->actingAsPlatformUser($this->admin);

        $this->put(gatewayUrl('sslcommerz'), [
            'sandbox' => true,
            'enabled' => true,
            'secrets' => ['store_id' => 'panel-store', 'store_password' => 'panel-pass'],
        ])->assertRedirect();

        $this->delete(gatewayUrl('sslcommerz'))->assertRedirect();

        expect(PaymentGatewayAccount::query()->count())->toBe(0)
            ->and(resolveGateway('sslcommerz')['secrets'])->toBe(['store_id' => 'env-store', 'store_password' => 'env-pass']);
    });
});

it('builds a configured gateway from the panel credentials', function () {
    $this->actingAsPlatformUser($this->admin);

    PaymentGatewayAccount::query()->create([
        'gateway' => 'aamarpay',
        'is_enabled' => true,
        'settings' => ['sandbox' => true],
        'secrets' => ['store_id' => 'panel-aamar', 'signature_key' => 'panel-sig'],
    ]);

    app()->forgetInstance(GatewayRegistry::class);
    $gateway = app(GatewayRegistry::class)->find('aamarpay');

    expect($gateway)->not->toBeNull()->and($gateway->isConfigured())->toBeTrue();
});
