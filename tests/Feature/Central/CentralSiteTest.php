<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Tenancy\Enums\TenantStatus;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
});

describe('the welcome page', function () {
    it('renders with the platform defaults and no workspace data', function () {
        $this->get('http://localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Central/Welcome')
                ->where('domain', 'localhost')
                ->where('app.area', 'central')
                ->where('app.name', config('app.name'))
                ->where('app.locale', 'en')
                ->where('app.fallbackLocale', 'en')
                ->where('app.locales', ['en' => 'English', 'bn' => 'বাংলা'])
                ->where('app.timezone', 'Asia/Dhaka')
                ->where('app.currency', 'BDT')
                ->where('app.numerals', 'latn')
                ->missing('tenant')
                ->missing('auth')
                ->missing('navigation'));
    });

    it('answers the health check on every host', function (string $host) {
        $this->get("http://{$host}/up")->assertOk();
    })->with(['localhost', 'admin.localhost', 'acme.localhost']);
});

describe('each area only tells the browser about its own routes', function () {
    it('lists central routes on the central site', function () {
        $html = $this->get('http://localhost/')->getContent();

        expect($html)->toContain('central.workspace.find')
            ->not->toContain('core.members.index', 'auth.login', 'platform.dashboard', 'platform.login', 'horizon.');
    });

    it('lists workspace routes in a workspace', function () {
        $html = $this->get('http://acme.localhost/login')->getContent();

        expect($html)->toContain('auth.login', 'core.members.index')
            ->not->toContain('platform.dashboard', 'platform.login', 'central.workspace.find', 'horizon.');
    });

    it('lists admin routes in the admin panel', function () {
        $html = $this->get('http://admin.localhost/login')->getContent();

        expect($html)->toContain('platform.login', 'platform.tenants.index')
            ->not->toContain('core.members.index', 'auth.login.store', 'central.workspace.find');
    });
});

describe('finding a workspace', function () {
    it('sends someone to the sign-in page of their workspace', function (string $typed) {
        $this->post('http://localhost/workspace', ['workspace' => $typed])
            ->assertRedirect('http://acme.localhost/login');
    })->with([
        'the name' => ['acme'],
        'any case' => ['ACME'],
        'padded' => ['  acme  '],
        'the host' => ['acme.localhost'],
        'a pasted link' => ['https://acme.localhost/login'],
        'a pasted link with a path' => ['http://acme.localhost/team/members?x=1'],
    ]);

    it('tells an Inertia visit to leave for the other origin', function () {
        $this->withHeader('X-Inertia', 'true')
            ->post('http://localhost/workspace', ['workspace' => 'acme'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'http://acme.localhost/login');
    });

    it('says the same thing for an unknown, reserved or deleted workspace', function (string $typed) {
        $this->createTenant('gone')->delete();

        $this->from('http://localhost/')
            ->post('http://localhost/workspace', ['workspace' => $typed])
            ->assertRedirect('http://localhost/')
            ->assertSessionHasErrors(['workspace' => 'We could not find a workspace with that address.']);
    })->with([
        'unknown' => ['nobody'],
        'reserved' => ['admin'],
        'reserved, as a link' => ['https://www.localhost'],
        'deleted' => ['gone'],
        'only punctuation' => ['://.'],
        'a workspace id instead of an address' => fn () => $this->acme->id,
    ]);

    it('still points at a suspended workspace, which then explains itself', function () {
        $this->acme->update(['status' => TenantStatus::Suspended]);

        $this->post('http://localhost/workspace', ['workspace' => 'acme'])->assertRedirect('http://acme.localhost/login');
        $this->get('http://acme.localhost/login')->assertForbidden();
    });

    it('validates what was typed', function (mixed $typed) {
        $this->post('http://localhost/workspace', ['workspace' => $typed])->assertSessionHasErrors('workspace');
    })->with([
        'nothing' => [''],
        'too long' => [str_repeat('a', 64)],
        'an array' => [['acme']],
    ]);

    it('limits how often the finder can be used to probe for workspaces', function () {
        foreach (range(1, 20) as $attempt) {
            $this->post('http://localhost/workspace', ['workspace' => "guess-{$attempt}"])->assertSessionHasErrors('workspace');
        }

        $this->post('http://localhost/workspace', ['workspace' => 'acme'])->assertTooManyRequests();
    });

    it('exists on the central site only', function () {
        $this->post('http://acme.localhost/workspace', ['workspace' => 'acme'])->assertNotFound();
        $this->post('http://admin.localhost/workspace', ['workspace' => 'acme'])->assertNotFound();
    });
});

describe('choosing a language', function () {
    it('remembers a visitor\'s choice in a cookie on the central site', function () {
        $this->from('http://localhost/')
            ->post('http://localhost/locale', ['locale' => 'bn'])
            ->assertRedirect('http://localhost/')
            ->assertCookie('locale', 'bn');

        $this->withCookie('locale', 'bn')
            ->get('http://localhost/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('app.locale', 'bn'))
            ->assertSee('<html lang="bn"', escape: false);
    });

    it('rejects a language the interface is not translated into', function (mixed $locale) {
        $this->post('http://localhost/locale', ['locale' => $locale])
            ->assertSessionHasErrors('locale')
            ->assertCookieMissing('locale');
    })->with(['fr', '', 'EN', '../bn', [['bn']]]);

    it('ignores a cookie holding an unsupported language', function () {
        $this->withCookie('locale', 'fr')
            ->get('http://localhost/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('app.locale', 'en'));
    });

    it('starts a workspace\'s pages in the workspace\'s own language', function () {
        $this->createTenant('globex', ['locale' => 'bn', 'timezone' => 'Asia/Kolkata', 'currency' => 'INR']);

        $this->get('http://globex.localhost/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'bn')
                ->where('app.timezone', 'Asia/Kolkata')
                ->where('app.currency', 'INR'));

        // The next visitor of another workspace is not served the previous one's language.
        $this->get('http://acme.localhost/login')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'en')
                ->where('app.timezone', 'Asia/Dhaka')
                ->where('app.currency', 'BDT'));
    });

    it('lets a guest of a workspace override its language with their cookie', function () {
        $this->from('http://acme.localhost/login')
            ->post('http://acme.localhost/locale', ['locale' => 'bn'])
            ->assertRedirect('http://acme.localhost/login')
            ->assertCookie('locale', 'bn');

        $this->withCookie('locale', 'bn')
            ->get('http://acme.localhost/login')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('app.locale', 'bn'));
    });

    it('saves a signed-in member\'s choice on their account, where it wins over cookie and workspace', function () {
        $member = $this->addMember($this->acme, Role::EMPLOYEE);

        $this->actingAsMember($this->acme, $member)
            ->post('http://acme.localhost/locale', ['locale' => 'bn'])
            ->assertRedirect()
            ->assertCookie('locale', 'bn');

        expect($member->refresh()->locale)->toBe('bn');

        // Account says bn, cookie says en, workspace says en.
        $this->withCookie('locale', 'en')
            ->get('http://acme.localhost/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('app.locale', 'bn')
                ->where('auth.user.locale', 'bn'));
    });

    it('does not touch any account when a guest chooses', function () {
        $member = $this->addMember($this->acme, Role::EMPLOYEE);

        $this->post('http://acme.localhost/locale', ['locale' => 'bn']);
        $this->post('http://localhost/locale', ['locale' => 'bn']);

        expect($member->refresh()->locale)->toBeNull();
    });
});
