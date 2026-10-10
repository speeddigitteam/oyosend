<?php

declare(strict_types=1);

use App\Core\Tenancy\Models\Domain;
use Inertia\Testing\AssertableInertia;

/*
 * The workspace settings page: company profile and regional settings. The
 * logo has its own file (WorkspaceLogoTest).
 */
beforeEach(function () {
    $this->acme = $this->createTenant('acme', ['name' => 'Acme']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex']);

    // Both sides of `core.settings.manage`, with roles that hold nothing else of note.
    $this->createRole($this->acme, 'Office manager', ['core.settings.manage']);
    $this->createRole($this->acme, 'Clerk', ['core.members.view']);

    $this->manager = $this->addMember($this->acme, 'Office manager');
    $this->clerk = $this->addMember($this->acme, 'Clerk');

    /** A complete, valid form: every field the page sends. */
    $this->profile = fn (array $override = []): array => [
        'name' => 'Acme Trading',
        'legal_name' => 'Acme Trading Limited',
        'email' => 'hello@acme.example',
        'phone' => '+880 1700-000000',
        'website' => 'https://acme.example',
        'address' => "House 12, Road 5\nDhanmondi",
        'city' => 'Dhaka',
        'postal_code' => '1205',
        'country' => 'BD',
        'tax_id' => 'BIN-000123456',
        'locale' => 'bn',
        'timezone' => 'Asia/Kolkata',
        'currency' => 'USD',
        ...$override,
    ];

    /** What is stored now, in the shape of the form. */
    $this->stored = fn ($tenant): array => $tenant->refresh()->only(array_keys(($this->profile)()));
});

describe('the page', function () {
    it('shows the profile, the regional settings and the choices for them', function () {
        $this->acme->update(['legal_name' => 'Acme Limited', 'country' => 'BD', 'tax_id' => 'BIN-1']);

        $this->actingAsMember($this->acme, $this->manager)
            ->get('http://acme.localhost/settings/workspace')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Settings/Workspace')
                ->where('workspace', [
                    'name' => 'Acme',
                    'legalName' => 'Acme Limited',
                    'email' => null,
                    'phone' => null,
                    'website' => null,
                    'address' => null,
                    'city' => null,
                    'postalCode' => null,
                    'country' => 'BD',
                    'taxId' => 'BIN-1',
                    'locale' => 'en',
                    'timezone' => 'Asia/Dhaka',
                    'currency' => 'BDT',
                    'url' => 'http://acme.localhost/',
                    'logoUrl' => null,
                ])
                ->where('currencies', config('platform.currencies'))
                ->where('timezones', fn ($timezones): bool => collect($timezones)->contains('Asia/Dhaka')
                    && collect($timezones)->contains('Europe/London')
                    && collect($timezones)->contains('UTC')
                    && count($timezones) > 300));
    });

    it('offers only time zones and currencies that saving accepts', function () {
        $props = $this->actingAsMember($this->acme, $this->manager)
            ->get('http://acme.localhost/settings/workspace')
            ->inertiaProps();

        // The ends of both lists, and something in the middle.
        foreach ([$props['timezones'][0], end($props['timezones']), 'America/Argentina/Buenos_Aires'] as $timezone) {
            $this->put('http://acme.localhost/settings/workspace', ($this->profile)(['timezone' => $timezone]))
                ->assertSessionHasNoErrors();
        }

        foreach ($props['currencies'] as $currency) {
            $this->put('http://acme.localhost/settings/workspace', ($this->profile)(['currency' => $currency]))
                ->assertSessionHasNoErrors();
        }

        expect($this->acme->refresh()->currency)->toBe(end($props['currencies']));
    });

    it('is only for signed-in members', function () {
        $this->get('http://acme.localhost/settings/workspace')->assertRedirect('http://acme.localhost/login');
        $this->put('http://acme.localhost/settings/workspace', ($this->profile)())->assertRedirect('http://acme.localhost/login');

        expect($this->acme->refresh()->name)->toBe('Acme');
    });
});

describe('saving', function () {
    it('saves every field and records what changed', function () {
        $before = ($this->stored)($this->acme);

        $this->actingAsMember($this->acme, $this->manager)
            ->from('http://acme.localhost/settings/workspace')
            ->put('http://acme.localhost/settings/workspace', ($this->profile)())
            ->assertRedirect('http://acme.localhost/settings/workspace')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect(($this->stored)($this->acme))->toBe(($this->profile)());

        $entry = $this->auditTrail($this->acme, 'workspace.settings_updated')->sole();

        expect($entry->actor_type)->toBe('user')
            ->and($entry->actor_id)->toBe($this->manager->id)
            ->and($entry->actor_label)->toBe($this->manager->email)
            ->and($entry->subject_type)->toBe('tenant')
            ->and($entry->subject_id)->toBe($this->acme->id)
            // A JSON column: MySQL returns the keys in its own order.
            ->and($entry->properties)->toEqual(['old' => $before, 'attributes' => ($this->profile)()]);
    });

    it('records only the fields that changed', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->put('http://acme.localhost/settings/workspace', ($this->profile)());
        $this->put('http://acme.localhost/settings/workspace', ($this->profile)(['city' => 'Chattogram', 'currency' => 'BDT']));

        $entries = $this->auditTrail($this->acme, 'workspace.settings_updated');

        expect($entries)->toHaveCount(2)
            ->and($entries->last()->properties)->toEqual([
                'old' => ['city' => 'Dhaka', 'currency' => 'USD'],
                'attributes' => ['city' => 'Chattogram', 'currency' => 'BDT'],
            ]);
    });

    it('writes no audit entry when the form is saved as it was opened', function () {
        // A workspace nobody has saved yet, sent back exactly what is stored.
        $before = ($this->stored)($this->acme);
        $updatedAt = $this->acme->updated_at;

        $this->travel(5)->minutes();

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', $before)
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        expect($this->auditTrail($this->acme, 'workspace.settings_updated'))->toBeEmpty()
            ->and(($this->stored)($this->acme))->toBe($before)
            ->and($this->acme->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('writes no second audit entry when the same values are saved again', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->put('http://acme.localhost/settings/workspace', ($this->profile)());

        $updatedAt = $this->acme->refresh()->updated_at;
        $this->travel(5)->minutes();

        $this->put('http://acme.localhost/settings/workspace', ($this->profile)())
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'workspace.settings_updated'))->toHaveCount(1)
            ->and($this->acme->refresh()->updated_at->equalTo($updatedAt))->toBeTrue();
    });

    it('clears the optional fields when they are sent empty', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->put('http://acme.localhost/settings/workspace', ($this->profile)());

        // The page sends an emptied field as an empty string.
        $this->put('http://acme.localhost/settings/workspace', [
            'name' => 'Acme Trading',
            'legal_name' => '',
            'email' => '',
            'phone' => '',
            'website' => '',
            'address' => '',
            'city' => '',
            'postal_code' => '',
            'country' => '',
            'tax_id' => '',
            'locale' => 'bn',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'USD',
        ])->assertSessionHasNoErrors();

        expect(($this->stored)($this->acme))->toBe([
            'name' => 'Acme Trading',
            'legal_name' => null,
            'email' => null,
            'phone' => null,
            'website' => null,
            'address' => null,
            'city' => null,
            'postal_code' => null,
            'country' => null,
            'tax_id' => null,
            'locale' => 'bn',
            'timezone' => 'Asia/Kolkata',
            'currency' => 'USD',
        ]);
    });

    it('changes this workspace only', function () {
        $before = ($this->stored)($this->globex);

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)())
            ->assertSessionHasNoErrors();

        expect(($this->stored)($this->globex))->toBe($before)
            ->and($this->auditTrail($this->globex, 'workspace.settings_updated'))->toBeEmpty();

        // And a session of one workspace is no key to another's settings.
        $this->put('http://globex.localhost/settings/workspace', ($this->profile)(['name' => 'Taken over']))
            ->assertRedirect('http://globex.localhost/login');

        expect($this->globex->refresh()->name)->toBe('Globex');
    });
});

describe('validation', function () {
    it('rejects a field that is not valid and saves nothing', function (string $field, mixed $value) {
        $before = ($this->stored)($this->acme);

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)([$field => $value]))
            ->assertSessionHasErrors($field);

        expect(($this->stored)($this->acme))->toBe($before)
            ->and($this->auditTrail($this->acme, 'workspace.settings_updated'))->toBeEmpty();
    })->with([
        'no name' => ['name', ''],
        'a name that is too long' => ['name', str_repeat('a', 256)],
        'a name that is not text' => ['name', ['Acme']],
        'a legal name that is too long' => ['legal_name', str_repeat('a', 256)],
        'an email that is not one' => ['email', 'hello-at-acme'],
        'a phone that is too long' => ['phone', str_repeat('1', 41)],
        'a website without a scheme' => ['website', 'acme.example'],
        'a website that is not http or https' => ['website', 'ftp://acme.example'],
        'a website that is a script' => ['website', 'javascript:alert(1)'],
        'an address that is too long' => ['address', str_repeat('a', 501)],
        'a city that is too long' => ['city', str_repeat('a', 121)],
        'a postal code that is too long' => ['postal_code', str_repeat('1', 21)],
        'a country in lower case' => ['country', 'bd'],
        'a country code of three letters' => ['country', 'BGD'],
        'a country name' => ['country', 'Bangladesh'],
        'a country code with a digit' => ['country', 'B1'],
        'a tax ID that is too long' => ['tax_id', str_repeat('1', 61)],
        'no language' => ['locale', ''],
        'a language the interface is not translated into' => ['locale', 'fr'],
        'a language in the wrong case' => ['locale', 'BN'],
        'no time zone' => ['timezone', ''],
        'a time zone that does not exist' => ['timezone', 'Mars/Olympus_Mons'],
        'a UTC offset instead of a time zone' => ['timezone', '+06:00'],
        'no currency' => ['currency', ''],
        'a currency that is not offered' => ['currency', 'XAU'],
        'a currency in lower case' => ['currency', 'usd'],
    ]);

    it('accepts http and https websites', function (string $website) {
        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)(['website' => $website]))
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->website)->toBe($website);
    })->with(['http://acme.example', 'https://www.acme.example/about']);
});

describe('what cannot be changed here', function () {
    it('keeps the workspace address, whatever is sent along', function () {
        $domain = Domain::query()->where('tenant_id', $this->acme->id)->sole();

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)([
                'slug' => 'initech',
                'url' => 'http://initech.localhost',
                'domain' => 'initech',
            ]))
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->slug)->toBe('acme')
            ->and($this->acme->name)->toBe('Acme Trading')
            ->and($domain->refresh()->domain)->toBe('acme')
            ->and(Domain::query()->where('domain', 'initech')->exists())->toBeFalse();

        // Still where it was, and nowhere else.
        $this->get('http://acme.localhost/settings/workspace')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('workspace.url', 'http://acme.localhost/'));
        $this->get('http://initech.localhost/login')->assertNotFound();

        expect(json_encode($this->auditTrail($this->acme, 'workspace.settings_updated')->sole()->properties))
            ->not->toContain('initech');
    });

    it('ignores every other column of the workspace', function () {
        $before = $this->acme->refresh()->only(['id', 'status', 'logo_path', 'trial_ends_at', 'suspended_at', 'onboarded_at', 'deleted_at']);

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)([
                'id' => $this->globex->id,
                'status' => 'suspended',
                'logo_path' => 'branding/other.png',
                'trial_ends_at' => '2099-01-01 00:00:00',
                'suspended_at' => '2026-01-01 00:00:00',
                'onboarded_at' => null,
                'deleted_at' => '2026-01-01 00:00:00',
            ]))
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->only(array_keys($before)))->toEqual($before)
            ->and($this->acme->isAccessible())->toBeTrue();
    });
});

describe('permission', function () {
    it('lets someone who holds core.settings.manage open and save the settings', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->get('http://acme.localhost/settings/workspace')->assertOk();
        $this->put('http://acme.localhost/settings/workspace', ($this->profile)())->assertRedirect()->assertSessionHasNoErrors();

        expect($this->acme->refresh()->name)->toBe('Acme Trading');
    });

    it('refuses someone who does not hold it', function () {
        $this->actingAsMember($this->acme, $this->clerk);

        $this->get('http://acme.localhost/settings/workspace')->assertForbidden();
        $this->put('http://acme.localhost/settings/workspace', ($this->profile)())->assertForbidden();

        expect($this->acme->refresh()->name)->toBe('Acme')
            ->and($this->auditTrail($this->acme, 'workspace.settings_updated'))->toBeEmpty();
    });

    it('lists the page in the sidebar only for those who may open it', function () {
        $entries = fn ($user): array => collect($this->startNewBrowserSession()
            ->actingAsMember($this->acme, $user)
            ->get('http://acme.localhost/settings/profile')
            ->inertiaProps('navigation'))
            ->flatMap(fn (array $section): array => array_column($section['items'], 'route'))
            ->all();

        expect($entries($this->manager))->toContain('core.settings.workspace')
            ->and($entries($this->clerk))->not->toContain('core.settings.workspace');
    });
});

describe('what the rest of the workspace sees afterwards', function () {
    it('uses the new time zone, currency and name on the next request', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->get('http://acme.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'en')
                ->where('app.timezone', 'Asia/Dhaka')
                ->where('app.currency', 'BDT')
                ->where('tenant.name', 'Acme'));

        $this->put('http://acme.localhost/settings/workspace', ($this->profile)());

        $this->get('http://acme.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'bn')
                ->where('app.timezone', 'Asia/Kolkata')
                ->where('app.currency', 'USD')
                ->where('tenant.name', 'Acme Trading')
                ->where('tenant.slug', 'acme')
                ->where('workspace.locale', 'bn')
                ->where('workspace.timezone', 'Asia/Kolkata')
                ->where('workspace.currency', 'USD'));

        // Everyone in the workspace, not only whoever saved.
        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->clerk)
            ->get('http://acme.localhost/settings/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'bn')
                ->where('app.timezone', 'Asia/Kolkata')
                ->where('app.currency', 'USD'));

        // And the sign-in page, for visitors who have chosen no language.
        $this->startNewBrowserSession()
            ->get('http://acme.localhost/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('app.locale', 'bn'));
    });

    it('leaves the language of a member who chose their own', function () {
        $this->clerk->update(['locale' => 'en']);

        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)(['locale' => 'bn']));

        $this->startNewBrowserSession()
            ->actingAsMember($this->acme, $this->clerk)
            ->get('http://acme.localhost/settings/profile')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'en')
                // The time zone and the currency are the workspace's, not a person's.
                ->where('app.timezone', 'Asia/Kolkata')
                ->where('app.currency', 'USD'));
    });

    it('does not change the regional settings of another workspace', function () {
        $this->actingAsMember($this->acme, $this->manager)
            ->put('http://acme.localhost/settings/workspace', ($this->profile)());

        $this->startNewBrowserSession()
            ->actingAsOwner($this->globex)
            ->get('http://globex.localhost/settings/workspace')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'en')
                ->where('app.timezone', 'Asia/Dhaka')
                ->where('app.currency', 'BDT')
                ->where('workspace.name', 'Globex')
                ->where('workspace.url', 'http://globex.localhost/'));
    });
});
