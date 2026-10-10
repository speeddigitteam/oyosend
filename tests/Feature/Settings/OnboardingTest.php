<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;

/*
 * First-run setup. A workspace someone signed up for starts out "not
 * onboarded": whoever may change its settings is sent from the dashboard to a
 * short form once, and never again.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

    // The way sign-up provisions a workspace.
    $this->acme = $this->createTenant('acme', ['name' => 'Acme', 'onboarded' => false]);
    $this->owner = $this->ownerOf($this->acme);

    // Both sides of `core.settings.manage`.
    $this->createRole($this->acme, 'Office manager', ['core.settings.manage']);
    $this->createRole($this->acme, 'Clerk', ['core.members.view']);

    $this->manager = $this->addMember($this->acme, 'Office manager');
    $this->clerk = $this->addMember($this->acme, 'Clerk');

    /** What the form sends. */
    $this->answers = fn (array $override = []): array => [
        'name' => 'Acme Trading',
        'legal_name' => 'Acme Trading Limited',
        'phone' => '+880 1700-000000',
        'country' => 'BD',
        'locale' => 'bn',
        'timezone' => 'Asia/Kolkata',
        'currency' => 'USD',
        ...$override,
    ];
});

describe('a workspace that has not been set up', function () {
    it('starts out that way only when it was provisioned so', function () {
        expect($this->acme->refresh()->isOnboarded())->toBeFalse()
            ->and($this->acme->onboarded_at)->toBeNull();
    });

    it('sends its owner from the dashboard to first-run setup', function () {
        $this->actingAsMember($this->acme, $this->owner)
            ->get('http://acme.localhost')
            ->assertRedirect('http://acme.localhost/onboarding');
    });

    it('sends anyone who may change the settings there too', function (string $role) {
        $member = $this->addMember($this->acme, $role);

        $this->actingAsMember($this->acme, $member)
            ->get('http://acme.localhost')
            ->assertRedirect('http://acme.localhost/onboarding');
    })->with(['a custom role that holds core.settings.manage' => 'Office manager', 'an admin' => Role::ADMIN]);

    it('leaves a member who may not change the settings on the dashboard', function (string $role) {
        $member = $this->addMember($this->acme, $role);

        $this->actingAsMember($this->acme, $member)
            ->get('http://acme.localhost')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Dashboard'));

        expect($this->acme->refresh()->isOnboarded())->toBeFalse();
    })->with(['a custom role without it' => 'Clerk', 'an employee' => Role::EMPLOYEE, 'a manager' => Role::MANAGER]);

    it('shows the form with what is known so far and the choices for the rest', function () {
        $this->acme->update(['phone' => '+880 1700-111111', 'country' => 'BD']);

        $this->actingAsMember($this->acme, $this->owner)
            ->get('http://acme.localhost/onboarding')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Onboarding')
                ->where('workspace', [
                    'name' => 'Acme',
                    'legalName' => null,
                    'phone' => '+880 1700-111111',
                    'country' => 'BD',
                    'locale' => 'en',
                    'timezone' => 'Asia/Dhaka',
                    'currency' => 'BDT',
                ])
                ->where('currencies', config('platform.currencies'))
                ->where('timezones', fn ($timezones): bool => collect($timezones)->contains('Asia/Dhaka') && count($timezones) > 300));
    });

    it('never traps the person: their own settings and signing out stay open', function () {
        $this->actingAsMember($this->acme, $this->owner);

        $this->get('http://acme.localhost/settings/profile')->assertOk();
        $this->get('http://acme.localhost/settings/security')->assertOk();
        $this->post('http://acme.localhost/logout')->assertRedirect('http://acme.localhost/login');
    });
});

describe('finishing setup', function () {
    it('saves the answers, marks the workspace as set up and records it once', function () {
        $this->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/onboarding', ($this->answers)())
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors()
            ->assertInertiaFlash('toast.type', 'success');

        $this->acme->refresh();

        expect($this->acme->only(array_keys(($this->answers)())))->toBe(($this->answers)())
            ->and($this->acme->isOnboarded())->toBeTrue()
            ->and($this->acme->onboarded_at->toDateTimeString())->toBe('2026-03-10 09:00:00');

        $onboarded = $this->auditTrail($this->acme, 'workspace.onboarded')->sole();

        expect($onboarded->actor_id)->toBe($this->owner->id)
            ->and($onboarded->subject_type)->toBe('tenant')
            ->and($onboarded->subject_id)->toBe($this->acme->id);

        // What changed is on record as well, like any other change of settings.
        expect($this->auditTrail($this->acme, 'workspace.settings_updated')->sole()->properties)->toEqual([
            'old' => ['name' => 'Acme', 'legal_name' => null, 'phone' => null, 'country' => null, 'locale' => 'en', 'timezone' => 'Asia/Dhaka', 'currency' => 'BDT'],
            'attributes' => ($this->answers)(),
        ]);

        // The dashboard is the dashboard from now on.
        $this->get('http://acme.localhost')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Dashboard')
                ->where('tenant.name', 'Acme Trading')
                ->where('app.timezone', 'Asia/Kolkata')
                ->where('app.currency', 'USD'));
    });

    it('is finished by confirming the details as they are', function () {
        $this->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/onboarding', [
                'name' => 'Acme',
                'legal_name' => '',
                'phone' => '',
                'country' => '',
                'locale' => 'en',
                'timezone' => 'Asia/Dhaka',
                'currency' => 'BDT',
            ])
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors();

        expect($this->acme->refresh()->isOnboarded())->toBeTrue()
            ->and($this->auditTrail($this->acme, 'workspace.onboarded'))->toHaveCount(1)
            // Nothing about the workspace changed, so nothing else is recorded.
            ->and($this->auditTrail($this->acme, 'workspace.settings_updated'))->toBeEmpty();
    });

    it('records the setup only once, however often the form arrives', function () {
        $this->actingAsMember($this->acme, $this->owner);

        $this->post('http://acme.localhost/onboarding', ($this->answers)())->assertRedirect('http://acme.localhost');

        $this->travel(2)->hours();

        // A second tab, a double click, a replayed request.
        $this->post('http://acme.localhost/onboarding', ($this->answers)(['name' => 'Acme Again']))
            ->assertRedirect('http://acme.localhost')
            ->assertSessionHasNoErrors();

        expect($this->auditTrail($this->acme, 'workspace.onboarded'))->toHaveCount(1)
            ->and($this->acme->refresh()->onboarded_at->toDateTimeString())->toBe('2026-03-10 09:00:00');
    });

    it('takes only what the form asks for', function () {
        $this->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/onboarding', ($this->answers)([
                // The rest of the profile belongs to the settings page ...
                'email' => 'hello@acme.example',
                'website' => 'https://acme.example',
                'address' => 'House 12',
                'city' => 'Dhaka',
                'postal_code' => '1205',
                'tax_id' => 'BIN-1',
                // ... and these to nobody in the workspace.
                'slug' => 'initech',
                'status' => 'suspended',
                'onboarded_at' => '2020-01-01 00:00:00',
                'logo_path' => 'branding/other.png',
            ]))
            ->assertSessionHasNoErrors();

        $this->acme->refresh();

        expect($this->acme->only(['email', 'website', 'address', 'city', 'postal_code', 'tax_id', 'logo_path']))
            ->toBe(['email' => null, 'website' => null, 'address' => null, 'city' => null, 'postal_code' => null, 'tax_id' => null, 'logo_path' => null])
            ->and($this->acme->slug)->toBe('acme')
            ->and($this->acme->isAccessible())->toBeTrue()
            ->and($this->acme->onboarded_at->toDateTimeString())->toBe('2026-03-10 09:00:00');
    });

    it('rejects an answer that is not valid and leaves the workspace as it was', function (string $field, mixed $value) {
        $this->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/onboarding', ($this->answers)([$field => $value]))
            ->assertSessionHasErrors($field);

        expect($this->acme->refresh()->isOnboarded())->toBeFalse()
            ->and($this->acme->name)->toBe('Acme')
            ->and($this->auditTrail($this->acme, 'workspace.onboarded'))->toBeEmpty()
            ->and($this->auditTrail($this->acme, 'workspace.settings_updated'))->toBeEmpty();

        // Still on its way to setup.
        $this->get('http://acme.localhost')->assertRedirect('http://acme.localhost/onboarding');
    })->with([
        'no name' => ['name', ''],
        'a legal name that is too long' => ['legal_name', str_repeat('a', 256)],
        'a phone that is too long' => ['phone', str_repeat('1', 41)],
        'a country in lower case' => ['country', 'bd'],
        'a language the interface is not translated into' => ['locale', 'fr'],
        'no language' => ['locale', ''],
        'a time zone that does not exist' => ['timezone', 'Mars/Olympus_Mons'],
        'no time zone' => ['timezone', ''],
        'a currency that is not offered' => ['currency', 'XAU'],
        'no currency' => ['currency', ''],
    ]);
});

describe('permission', function () {
    it('lets someone who holds core.settings.manage see and finish setup', function () {
        $this->actingAsMember($this->acme, $this->manager);

        $this->get('http://acme.localhost/onboarding')->assertOk();
        $this->post('http://acme.localhost/onboarding', ($this->answers)())->assertRedirect('http://acme.localhost');

        expect($this->acme->refresh()->isOnboarded())->toBeTrue()
            ->and($this->auditTrail($this->acme, 'workspace.onboarded')->sole()->actor_id)->toBe($this->manager->id);
    });

    it('refuses someone who does not hold it', function () {
        $this->actingAsMember($this->acme, $this->clerk);

        $this->get('http://acme.localhost/onboarding')->assertForbidden();
        $this->post('http://acme.localhost/onboarding', ($this->answers)())->assertForbidden();

        expect($this->acme->refresh()->isOnboarded())->toBeFalse()
            ->and($this->acme->name)->toBe('Acme')
            ->and($this->auditTrail($this->acme, 'workspace.onboarded'))->toBeEmpty();
    });

    it('is only for signed-in members', function () {
        $this->get('http://acme.localhost/onboarding')->assertRedirect('http://acme.localhost/login');
        $this->post('http://acme.localhost/onboarding', ($this->answers)())->assertRedirect('http://acme.localhost/login');

        expect($this->acme->refresh()->isOnboarded())->toBeFalse();
    });
});

describe('a workspace that has been set up', function () {
    it('sends the setup page back to the dashboard', function () {
        $this->actingAsMember($this->acme, $this->owner);

        $this->post('http://acme.localhost/onboarding', ($this->answers)());

        $this->get('http://acme.localhost/onboarding')->assertRedirect('http://acme.localhost');
    });

    it('is what every workspace created any other way already is', function () {
        $globex = $this->createTenant('globex');

        expect($globex->refresh()->isOnboarded())->toBeTrue()
            ->and($globex->onboarded_at->toDateTimeString())->toBe('2026-03-10 09:00:00');

        $this->actingAsOwner($globex);

        $this->get('http://globex.localhost')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Core/Pages/Dashboard'));
        $this->get('http://globex.localhost/onboarding')->assertRedirect('http://globex.localhost');

        expect($this->auditTrail($globex, 'workspace.onboarded'))->toBeEmpty();
    });

    it('keeps its own state, whatever another workspace does', function () {
        $globex = $this->createTenant('globex', ['onboarded' => false]);

        $this->actingAsMember($this->acme, $this->owner)
            ->post('http://acme.localhost/onboarding', ($this->answers)());

        expect($this->acme->refresh()->isOnboarded())->toBeTrue()
            ->and($globex->refresh()->isOnboarded())->toBeFalse()
            ->and($globex->name)->not->toBe('Acme Trading');

        $this->startNewBrowserSession()
            ->actingAsOwner($globex)
            ->get('http://globex.localhost')
            ->assertRedirect('http://globex.localhost/onboarding');
    });
});
