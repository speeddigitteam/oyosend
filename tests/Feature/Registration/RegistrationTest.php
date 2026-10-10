<?php

declare(strict_types=1);

use App\Core\Billing\Enums\BillingInterval;
use App\Core\Billing\Enums\SubscriptionStatus;
use App\Core\Billing\Mail\InvoiceIssuedMail;
use App\Core\Billing\Models\Subscription;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Registration\Mail\ConfirmRegistrationMail;
use App\Core\Registration\Models\WorkspaceRegistration;
use App\Core\Settings\PlatformSettings;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Tenant;
use App\Platform\Tenants\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * Signing up for a workspace on the central site (ADR 0008):
 *
 *  1. the form records a pending registration and emails a link;
 *  2. the link opens a page with one button, and opening it creates nothing;
 *  3. pressing the button creates the account (unless the address has one),
 *     the workspace it owns and its subscription.
 */
beforeEach(function () {
    config(['platform.registration.mode' => 'open']);

    // Provisioning a workspace gives its system roles their permissions.
    $this->syncPermissions();

    // Starter is invoiced from its first day; Pro starts with a trial.
    $this->starter = $this->createPlan(['key' => 'starter', 'name' => 'Starter', 'sort_order' => 1]);
    $this->pro = $this->createPlan(['key' => 'pro', 'name' => 'Pro', 'sort_order' => 2, 'trial_days' => 14]);

    /** What the sign-up form posts. */
    $this->form = fn (array $overrides = []): array => [
        'name' => 'Nadia Islam',
        'email' => 'nadia@example.test',
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        'company' => 'Acme Trading',
        'slug' => 'acme-trading',
        'locale' => 'en',
        'plan' => 'pro',
        'interval' => 'monthly',
        ...$overrides,
    ];

    $this->signUp = fn (array $overrides = []): TestResponse => $this
        ->from('http://localhost/register')
        ->post('http://localhost/register', ($this->form)($overrides));

    /** The token in the link of the most recently sent confirmation email. */
    $this->emailedToken = function (): string {
        $html = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getHtmlBody();

        expect(preg_match('#http://localhost/register/confirm/([A-Za-z0-9]{64})#', (string) $html, $match))->toBe(1);

        return $match[1];
    };

    /** Sign up, and return the token of the link that was emailed. */
    $this->register = function (array $overrides = []): string {
        ($this->signUp)($overrides)
            ->assertRedirect('http://localhost/register/sent')
            ->assertSessionHasNoErrors();

        return ($this->emailedToken)();
    };

    $this->confirmUrl = fn (string $token): string => "http://localhost/register/confirm/{$token}";

    $this->registrations = fn () => WorkspaceRegistration::query()->orderBy('id')->get();
});

describe('the sign-up form', function () {
    it('offers the plans a workspace may choose, the first one selected', function () {
        $this->createPlan(['key' => 'hidden', 'is_public' => false]);
        $this->createPlan(['key' => 'archived', 'is_active' => false]);
        $this->createFreePlan(['key' => 'free', 'name' => 'Free', 'sort_order' => 0]);

        $this->get('http://localhost/register')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Core/Pages/Central/Register')
                    ->where('domain', 'localhost')
                    ->where('selectedPlan', 'free')
                    ->where('needsApproval', false)
                    ->where('app.area', 'central')
                    ->where('app.locales', ['en' => 'English', 'bn' => 'বাংলা']);

                $plans = collect($page->toArray()['props']['plans'])->keyBy('key');

                // Active and public only, in the order the operator gave them.
                expect($plans->keys()->all())->toBe(['free', 'starter', 'pro'])
                    ->and($plans['free'])->toMatchArray(['name' => 'Free', 'monthlyAmount' => 0, 'yearlyAmount' => null, 'isFree' => true])
                    ->and($plans['starter'])->toMatchArray([
                        'name' => 'Starter',
                        'currency' => 'BDT',
                        'monthlyAmount' => 150000,
                        'yearlyAmount' => 1500000,
                        'trialDays' => 0,
                        'isFree' => false,
                    ])
                    ->and($plans['pro'])->toMatchArray(['trialDays' => 14]);
            });
    });

    it('starts on the plan that was picked on the landing page', function (string $query, string $selected) {
        $this->createPlan(['key' => 'hidden', 'is_public' => false]);

        $this->get('http://localhost/register'.$query)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('selectedPlan', $selected));
    })->with([
        'a plan on offer' => ['?plan=pro', 'pro'],
        'an unknown plan' => ['?plan=enterprise', 'starter'],
        'a plan that is not offered' => ['?plan=hidden', 'starter'],
        'not a plan at all' => ['?plan[]=pro', 'starter'],
    ]);

    it('has no plan to choose where none is on offer', function () {
        $this->starter->update(['is_active' => false]);
        $this->pro->update(['is_public' => false]);

        $this->get('http://localhost/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('plans', [])->where('selectedPlan', null));
    });

    it('says that a workspace has to be approved when that is how the platform is run', function () {
        app(PlatformSettings::class)->put(['registration.mode' => 'approval']);

        $this->get('http://localhost/register')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('needsApproval', true));
    });

    it('exists on the central site only, like the link it emails', function () {
        $token = ($this->register)();

        $this->createTenant('acme');

        foreach (['acme.localhost', 'admin.localhost'] as $host) {
            $this->get("http://{$host}/register")->assertNotFound();
            $this->post("http://{$host}/register", ($this->form)(['email' => 'other@example.test', 'slug' => 'other']))->assertNotFound();
            $this->get("http://{$host}/register/sent")->assertNotFound();
            $this->get("http://{$host}/register/confirm/{$token}")->assertNotFound();
            $this->post("http://{$host}/register/confirm/{$token}")->assertNotFound();
        }

        expect(($this->registrations)()->sole()->completed_at)->toBeNull()
            ->and(Tenant::query()->count())->toBe(1);
    });
});

describe('while registration is closed', function () {
    beforeEach(function () {
        // A link that was emailed while sign-up was still open.
        $this->token = ($this->register)();

        app(PlatformSettings::class)->put(['registration.mode' => 'closed']);
    });

    it('has no form', function () {
        $this->get('http://localhost/register')->assertNotFound();
    });

    it('takes no sign-up', function () {
        ($this->signUp)(['email' => 'late@example.test', 'slug' => 'late'])->assertNotFound();

        expect(($this->registrations)())->toHaveCount(1);
    });

    it('treats a link that was already emailed as no longer valid, and creates nothing from it', function () {
        $this->get(($this->confirmUrl)($this->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'invalid')
                ->where('token', null)
                ->where('company', null)
                // Nothing to offer: a new sign-up is not possible either.
                ->where('registrationOpen', false));

        $this->post(($this->confirmUrl)($this->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid')->where('registrationOpen', false));

        expect(Tenant::query()->count())->toBe(0)
            ->and(User::query()->count())->toBe(0)
            ->and(($this->registrations)()->sole()->completed_at)->toBeNull();
    });
});

describe('signing up', function () {
    it('records the sign-up, emails a link and creates nothing else', function () {
        Mail::fake();

        ($this->signUp)([
            'name' => '  Nadia Islam ',
            'email' => ' Nadia@Example.TEST ',
            'company' => ' Acme Trading ',
            'slug' => 'Acme-Trading',
            'locale' => 'bn',
            'plan' => 'pro',
            'interval' => 'yearly',
        ])
            ->assertRedirect('http://localhost/register/sent')
            ->assertSessionHasNoErrors();

        $registration = ($this->registrations)()->sole();

        expect($registration->email)->toBe('nadia@example.test')
            ->and($registration->name)->toBe('Nadia Islam')
            ->and($registration->company)->toBe('Acme Trading')
            ->and($registration->slug)->toBe('acme-trading')
            ->and($registration->locale)->toBe('bn')
            ->and($registration->plan_id)->toBe($this->pro->id)
            ->and($registration->interval)->toBe(BillingInterval::Yearly)
            ->and($registration->completed_at)->toBeNull()
            ->and($registration->tenant_id)->toBeNull()
            ->and($registration->ip_address)->toBe('127.0.0.1')
            ->and($registration->expires_at->diffInMinutes(now()->addHours(24), absolute: true))->toBeLessThan(1)
            ->and($registration->isWaiting())->toBeTrue();

        // The password is kept hashed, and is never part of what the model gives away.
        expect($registration->password)->not->toBe('a-good-password')
            ->and(Hash::check('a-good-password', $registration->password))->toBeTrue()
            ->and(json_encode($registration))->not->toContain($registration->password, $registration->token_hash);

        // Queued, to that address, in the language that was chosen.
        Mail::assertQueued(ConfirmRegistrationMail::class, 1);
        Mail::assertNothingSent();

        $mail = Mail::queued(ConfirmRegistrationMail::class)->sole();

        expect($mail->hasTo('nadia@example.test'))->toBeTrue()
            ->and($mail->locale)->toBe('bn')
            ->and($mail->queue)->toBe('notifications')
            ->and($mail->registration->is($registration))->toBeTrue();

        // The link carries the token; the table only its hash.
        $html = $mail->render();

        expect(preg_match('#http://localhost/register/confirm/([A-Za-z0-9]{64})#', $html, $match))->toBe(1)
            ->and($html)->toContain('Acme Trading', 'Nadia Islam', 'acme-trading.localhost');

        $token = $match[1];

        expect($registration->token_hash)->toBe(hash('sha256', $token))
            ->and(DB::table('workspace_registrations')->where('token_hash', $token)->exists())->toBeFalse()
            ->and(DB::table('workspace_registrations')->where('password', 'a-good-password')->exists())->toBeFalse();

        // No account and no workspace until the link is confirmed.
        expect(User::query()->count())->toBe(0)
            ->and(Tenant::query()->count())->toBe(0);
    });

    it('then says where the link was sent, for as long as that page is being looked at', function () {
        ($this->signUp)(['email' => 'Nadia@Example.test'])->assertRedirect('http://localhost/register/sent');

        foreach (['first visit', 'reload'] as $visit) {
            $this->get('http://localhost/register/sent')
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Core/Pages/Central/RegistrationSent')
                    ->where('email', 'Nadia@Example.test')
                    ->where('expiresInHours', 24));
        }

        // Nobody else is told an address: without a sign-up just made, back to the form.
        $this->startNewBrowserSession();
        $this->get('http://localhost/register/sent')->assertRedirect('http://localhost/register');
    });

    it('really sends the email, with a link to the central site', function () {
        ($this->signUp)(['locale' => 'en'])->assertSessionHasNoErrors();

        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        expect($message->getTo()[0]->getAddress())->toBe('nadia@example.test')
            ->and($message->getSubject())->toBe('Confirm your email to create Acme Trading')
            ->and($message->getHtmlBody())->toContain('http://localhost/register/confirm/', 'acme-trading.localhost', '24 hours');

        expect(hash('sha256', ($this->emailedToken)()))->toBe(($this->registrations)()->sole()->token_hash);
    });

    it('writes the email in the language that was chosen on the form', function () {
        ($this->signUp)(['locale' => 'bn'])->assertSessionHasNoErrors();

        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();

        expect($message->getSubject())->toBe(__('Confirm your email to create :company', ['company' => 'Acme Trading'], 'bn'))
            ->not->toBe('Confirm your email to create Acme Trading')
            ->and($message->getHtmlBody())->toContain(__('Confirm and create workspace', [], 'bn'), 'http://localhost/register/confirm/')
            // Writing an email in another language does not change the language of the request.
            ->and(app()->getLocale())->toBe('en');
    });

    it('answers an address that already has an account exactly like a new one', function () {
        $existing = User::factory()->create(['email' => 'owner@example.test']);

        $new = ($this->signUp)(['email' => 'nadia@example.test', 'slug' => 'first']);
        $known = ($this->signUp)(['email' => 'owner@example.test', 'slug' => 'second']);

        foreach ([$new, $known] as $response) {
            $response->assertStatus(302)
                ->assertRedirect('http://localhost/register/sent')
                ->assertSessionHasNoErrors();
        }

        expect($known->getContent())->toBe($new->getContent())
            ->and(($this->registrations)()->pluck('email')->all())->toBe(['nadia@example.test', 'owner@example.test'])
            ->and(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(2);

        $this->get('http://localhost/register/sent')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('email', 'owner@example.test')->where('expiresInHours', 24));

        // Still just the one account, untouched, and no workspace.
        expect(User::query()->count())->toBe(1)
            ->and($existing->fresh()->updated_at->equalTo($existing->updated_at))->toBeTrue()
            ->and(Tenant::query()->count())->toBe(0);
    });

    it('replaces an earlier sign-up of the same address: its link stops working and its address is free again', function () {
        $first = ($this->register)(['slug' => 'first-choice']);
        $second = ($this->register)(['email' => 'NADIA@example.test', 'slug' => 'second-choice']);

        expect($second)->not->toBe($first)
            ->and(($this->registrations)()->sole()->slug)->toBe('second-choice');

        $this->get(($this->confirmUrl)($first))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid'));
        $this->get(($this->confirmUrl)($second))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm')->where('address', 'second-choice.localhost'));

        // Somebody else can now have the address the first attempt was holding.
        ($this->signUp)(['email' => 'other@example.test', 'slug' => 'first-choice'])->assertSessionHasNoErrors();

        expect(($this->registrations)()->pluck('slug')->all())->toBe(['second-choice', 'first-choice']);
    });

    it('bills at an interval the plan is sold for', function () {
        $this->createPlan(['key' => 'monthly-only', 'yearly_amount' => null]);

        ($this->signUp)(['plan' => 'monthly-only', 'interval' => 'yearly'])->assertSessionHasNoErrors();

        expect(($this->registrations)()->sole()->interval)->toBe(BillingInterval::Monthly);
    });

    it('takes a sign-up without a plan where none is on offer', function () {
        $this->starter->update(['is_active' => false]);
        $this->pro->update(['is_active' => false]);

        ($this->signUp)(['plan' => null, 'interval' => null])->assertSessionHasNoErrors();

        $registration = ($this->registrations)()->sole();

        expect($registration->plan_id)->toBeNull()
            ->and($registration->interval)->toBeNull();
    });

    it('limits how often the form can be submitted', function () {
        foreach (range(1, 6) as $attempt) {
            ($this->signUp)(['email' => 'not-an-email'])->assertSessionHasErrors('email');
        }

        ($this->signUp)()->assertTooManyRequests();

        expect(($this->registrations)())->toBeEmpty();
    });
});

describe('validation', function () {
    beforeEach(function () {
        $this->createPlan(['key' => 'hidden', 'is_public' => false]);
        $this->createPlan(['key' => 'archived', 'is_active' => false]);
    });

    it('rejects what the form cannot take, at its field', function (array $input, string $field) {
        Mail::fake();

        ($this->signUp)($input)
            ->assertRedirect('http://localhost/register')
            ->assertSessionHasErrors($field);

        expect(($this->registrations)())->toBeEmpty();

        Mail::assertNothingQueued();
    })->with([
        'no name' => [['name' => ''], 'name'],
        'a name that is too long' => [['name' => str_repeat('a', 121)], 'name'],
        'no email' => [['email' => ''], 'email'],
        'not an email' => [['email' => 'nadia.example.test'], 'email'],
        'an email as an array' => [['email' => ['nadia@example.test']], 'email'],
        'no password' => [['password' => '', 'password_confirmation' => ''], 'password'],
        'a password that is too short' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
        'a password that was not repeated' => [['password_confirmation' => ''], 'password'],
        'a password repeated differently' => [['password_confirmation' => 'a-good-passw0rd'], 'password'],
        'no company' => [['company' => ''], 'company'],
        'a company name that is too long' => [['company' => str_repeat('a', 256)], 'company'],
        'no language' => [['locale' => ''], 'locale'],
        'a language the interface is not translated into' => [['locale' => 'fr'], 'locale'],
        'no plan, although plans are on offer' => [['plan' => ''], 'plan'],
        'a plan that does not exist' => [['plan' => 'enterprise'], 'plan'],
        'a plan that is not offered to workspaces' => [['plan' => 'hidden'], 'plan'],
        'a plan that was archived' => [['plan' => 'archived'], 'plan'],
        'a plan by its id instead of its key' => [fn () => ['plan' => $this->starter->id], 'plan'],
        'an interval that does not exist' => [['interval' => 'weekly'], 'interval'],
        'no workspace address' => [['slug' => ''], 'slug'],
    ]);

    it('accepts only a well-formed workspace address of three characters or more', function (string $slug) {
        ($this->signUp)(['slug' => $slug])->assertSessionHasErrors([
            'slug' => 'Use 3 to 63 lower-case letters, digits or hyphens, starting and ending with a letter or digit.',
        ]);

        expect(($this->registrations)())->toBeEmpty();
    })->with([
        'two characters' => ['ab'],
        'a space' => ['acme trading'],
        'an underscore' => ['acme_trading'],
        'a dot' => ['acme.trading'],
        'a leading hyphen' => ['-acme'],
        'a trailing hyphen' => ['acme-'],
        'a whole address' => ['acme.localhost'],
        'a link' => ['http://acme'],
        'letters that are not Latin' => ['ঢাকা-ট্রেডার্স'],
    ]);

    it('rejects a workspace address longer than a host name allows', function () {
        ($this->signUp)(['slug' => str_repeat('a', 64)])->assertSessionHasErrors('slug');
        ($this->signUp)(['email' => 'other@example.test', 'slug' => str_repeat('a', 63)])->assertSessionHasNoErrors();

        expect(($this->registrations)()->sole()->slug)->toBe(str_repeat('a', 63));
    });

    it('says the same about every workspace address that cannot be had', function (string $slug) {
        $this->createTenant('taken');
        $this->createTenant('gone')->delete();

        ($this->signUp)(['slug' => $slug])->assertSessionHasErrors(['slug' => 'This workspace address is not available.']);

        expect(($this->registrations)())->toBeEmpty();
    })->with([
        'reserved for the panel' => ['admin'],
        'reserved' => ['www'],
        'reserved, in capitals' => ['API'],
        'taken by a workspace' => ['taken'],
        'taken by a workspace, in capitals' => ['Taken'],
        'taken by a workspace that was deleted' => ['gone'],
    ]);

    it('keeps an address for the sign-up that asked first, until its link expires', function () {
        ($this->signUp)(['email' => 'first@example.test', 'slug' => 'acme'])->assertSessionHasNoErrors();

        ($this->signUp)(['email' => 'second@example.test', 'slug' => 'acme'])
            ->assertSessionHasErrors(['slug' => 'This workspace address is not available.']);

        // The link of the first sign-up is valid for 24 hours.
        $this->travel(23)->hours();

        ($this->signUp)(['email' => 'second@example.test', 'slug' => 'ACME'])->assertSessionHasErrors('slug');

        $this->travel(61)->minutes();

        ($this->signUp)(['email' => 'second@example.test', 'slug' => 'acme'])->assertSessionHasNoErrors();

        expect(($this->registrations)()->pluck('email')->all())->toBe(['first@example.test', 'second@example.test']);
    });
});

describe('the emailed link', function () {
    it('shows what is about to be created, and creates nothing by being opened', function () {
        $token = ($this->register)(['plan' => 'pro']);

        // Opened by a mail scanner, then by the person, then once more.
        foreach (range(1, 3) as $visit) {
            $this->get(($this->confirmUrl)($token))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Core/Pages/Central/ConfirmRegistration')
                    ->where('state', 'confirm')
                    ->where('token', $token)
                    ->where('company', 'Acme Trading')
                    ->where('email', 'nadia@example.test')
                    ->where('address', 'acme-trading.localhost')
                    ->where('plan', 'Pro')
                    ->where('loginUrl', null)
                    ->where('registrationOpen', true));
        }

        expect(User::query()->count())->toBe(0)
            ->and(Tenant::query()->count())->toBe(0)
            ->and(($this->registrations)()->sole()->isWaiting())->toBeTrue();
    });

    it('names no plan when none was chosen', function () {
        $this->starter->update(['is_active' => false]);
        $this->pro->update(['is_active' => false]);

        $token = ($this->register)(['plan' => null, 'interval' => null]);

        $this->get(($this->confirmUrl)($token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm')->where('plan', null));
    });

    it('treats an unknown link as no longer valid, without saying anything about any sign-up', function () {
        ($this->register)();

        $this->get(($this->confirmUrl)(str_repeat('z', 64)))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Central/ConfirmRegistration')
                ->where('state', 'invalid')
                ->where('token', null)
                ->where('company', null)
                ->where('email', null)
                ->where('address', null)
                ->where('plan', null)
                ->where('loginUrl', null)
                ->where('registrationOpen', true));

        $this->post(($this->confirmUrl)(str_repeat('z', 64)))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid'));

        expect(Tenant::query()->count())->toBe(0);
    });

    it('has no page at all for a malformed link', function (string $token) {
        $this->get(($this->confirmUrl)($token))->assertNotFound();
        $this->post(($this->confirmUrl)($token))->assertNotFound();
    })->with([
        'too short' => ['short'],
        'too long' => [str_repeat('a', 65)],
        'wrong characters' => [str_repeat('-', 64)],
    ]);

    it('stops working when it expires, and creates nothing after that', function () {
        $token = ($this->register)();

        $this->travel(23)->hours();

        $this->get(($this->confirmUrl)($token))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'confirm'));

        $this->travel(61)->minutes();

        $this->get(($this->confirmUrl)($token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid')->where('company', null));

        $this->post(($this->confirmUrl)($token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid'));

        expect(User::query()->count())->toBe(0)
            ->and(Tenant::query()->count())->toBe(0)
            ->and(($this->registrations)()->sole()->completed_at)->toBeNull();
    });

    it('creates nothing when the address was taken between sign-up and confirmation', function () {
        $token = ($this->register)();

        // A workspace made from the Super Admin panel does not ask the sign-ups.
        $this->createTenant('acme-trading');

        $accounts = User::query()->count();

        $this->post(($this->confirmUrl)($token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'invalid')
                ->where('loginUrl', null)
                ->where('registrationOpen', true));

        expect(Tenant::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe($accounts)
            ->and(User::query()->where('email', 'nadia@example.test')->exists())->toBeFalse()
            ->and(($this->registrations)()->sole()->completed_at)->toBeNull();
    });
});

describe('confirming as a new account', function () {
    it('creates the account, the workspace and its subscription, and takes the owner straight in', function () {
        // The handoff into the new workspace is redeemed on another host; keep it in a cache that is shared.
        $this->useDatabaseCache();
        $this->travelTo(Carbon::parse('2026-03-10 09:00:00'));

        $token = ($this->register)(['email' => 'Nadia@Example.test', 'locale' => 'bn', 'plan' => 'pro', 'interval' => 'yearly']);

        // The page posts as an Inertia visit, and is told to leave for the other origin.
        $response = $this->post(($this->confirmUrl)($token), [], ['X-Inertia' => 'true'])->assertStatus(409);
        $handoff = (string) $response->headers->get('X-Inertia-Location');

        expect($handoff)->toMatch('#^http://acme-trading\.localhost/auth/handoff\?token=[A-Za-z0-9]{64}$#');

        // The account: verified by the link, with the password and language of the form.
        $user = User::query()->sole();

        expect($user->email)->toBe('nadia@example.test')
            ->and($user->name)->toBe('Nadia Islam')
            ->and($user->locale)->toBe('bn')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('a-good-password', $user->password))->toBeTrue();

        // The workspace: active at that address, not yet through its first-run setup.
        $tenant = Tenant::query()->sole();

        expect($tenant->name)->toBe('Acme Trading')
            ->and($tenant->slug)->toBe('acme-trading')
            ->and($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->locale)->toBe('bn')
            ->and($tenant->onboarded_at)->toBeNull()
            ->and($tenant->domains()->pluck('domain')->all())->toBe(['acme-trading']);

        // Owned by the new account.
        $membership = $this->membershipOf($tenant, $user);

        expect($membership->is_owner)->toBeTrue()
            ->and($membership->status)->toBe(MembershipStatus::Active)
            ->and($this->ownerOf($tenant)->is($user))->toBeTrue()
            ->and($this->inTenant($tenant, fn (): array => $user->roles()->pluck('name')->all()))->toBe(['Owner']);

        // On the chosen plan, at the chosen interval, in its trial.
        $subscription = $this->subscriptionOf($tenant);

        expect($subscription->plan->key)->toBe('pro')
            ->and($subscription->status)->toBe(SubscriptionStatus::Trialing)
            ->and($subscription->interval)->toBe(BillingInterval::Yearly)
            ->and($subscription->amount)->toBe(1500000)
            ->and($subscription->trial_ends_at->toDateTimeString())->toBe('2026-03-24 09:00:00')
            ->and($this->invoicesOf($tenant))->toBeEmpty();

        // Recorded where the workspace's people can read it, and where the platform watches sign-ups.
        $entry = $this->auditTrail($tenant, 'workspace.registered')->sole();

        expect($entry->actor_id)->toBe($user->id)
            ->and($entry->subject_type)->toBe('tenant')
            ->and($entry->subject_id)->toBe($tenant->id)
            ->and($entry->properties)->toEqual(['plan' => 'pro']);

        $platformEntry = $this->auditTrail(null, 'platform.workspace.registered')->sole();

        expect($platformEntry->tenant_id)->toBeNull()
            ->and($platformEntry->actor_id)->toBe($user->id)
            ->and($platformEntry->subject_id)->toBe($tenant->id)
            ->and($platformEntry->properties)->toEqual(['status' => 'active', 'plan' => 'pro', 'owner' => 'nadia@example.test']);

        $registration = ($this->registrations)()->sole();

        expect($registration->completed_at)->not->toBeNull()
            ->and($registration->tenant_id)->toBe($tenant->id);

        // The other origin is another browser session: only the handoff signs the owner in there.
        $this->startNewBrowserSession();
        $this->assertGuest('web');

        $this->get($handoff)->assertRedirect('http://acme-trading.localhost');

        $this->assertAuthenticatedAs($user, 'web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBe($tenant->id);

        // And the first thing the workspace asks for is its setup.
        $this->get('http://acme-trading.localhost/')->assertRedirect('http://acme-trading.localhost/onboarding');
    });

    it('sends a browser that posted the form the ordinary way to the same place', function () {
        $token = ($this->register)();

        $response = $this->post(($this->confirmUrl)($token))->assertRedirect();

        expect((string) $response->headers->get('Location'))
            ->toMatch('#^http://acme-trading\.localhost/auth/handoff\?token=[A-Za-z0-9]{64}$#');
    });

    it('starts a plan without a trial with its first invoice, and emails it to the owner', function () {
        $token = ($this->register)(['plan' => 'starter', 'interval' => 'monthly']);

        $this->post(($this->confirmUrl)($token))->assertRedirect();

        $tenant = Tenant::query()->sole();
        $subscription = $this->subscriptionOf($tenant);
        $invoice = $this->invoicesOf($tenant)->sole();

        expect($subscription->plan->key)->toBe('starter')
            ->and($subscription->interval)->toBe(BillingInterval::Monthly)
            ->and($subscription->status)->toBe(SubscriptionStatus::PastDue)
            ->and($subscription->grantsAccess())->toBeTrue()
            ->and($invoice->total_amount)->toBe(150000);

        // The invoice is the new workspace's mail: queued with its tenant, sent to its owner.
        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        expect($message->getTo()[0]->getAddress())->toBe('nadia@example.test')
            ->and($message->getSubject())->toBe("Invoice {$invoice->number} for Acme Trading")
            ->and($message->getHtmlBody())->toContain("http://acme-trading.localhost/billing/invoices/{$invoice->id}");
    });

    it('queues that first invoice\'s email as the new workspace on a real queue', function () {
        $token = ($this->register)(['plan' => 'starter']);

        config(['queue.default' => 'database']);

        $this->post(($this->confirmUrl)($token))->assertRedirect();

        $tenant = Tenant::query()->sole();
        $invoice = $this->invoicesOf($tenant)->sole();
        $job = DB::table('jobs')->sole();
        $payload = json_decode($job->payload, true);

        // It only reaches the queue when the sign-up commits, and still carries the workspace it belongs to.
        expect($job->queue)->toBe('billing')
            ->and($payload['displayName'])->toBe(InvoiceIssuedMail::class)
            ->and($payload['tenant_id'])->toBe($tenant->id);

        // A worker with no tenant active picks it up.
        $this->inCentral(fn () => $this->artisan('queue:work', ['--once' => true, '--queue' => 'billing', '--tries' => 1, '--sleep' => 0]));

        expect(DB::table('failed_jobs')->count())->toBe(0);

        $message = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

        expect($message->getTo()[0]->getAddress())->toBe('nadia@example.test')
            ->and($message->getSubject())->toBe("Invoice {$invoice->number} for Acme Trading");
    });

    it('leaves the workspace without a subscription where no plan is on offer', function () {
        $this->starter->update(['is_active' => false]);
        $this->pro->update(['is_active' => false]);

        $token = ($this->register)(['plan' => null, 'interval' => null]);

        $this->post(($this->confirmUrl)($token))->assertRedirect();

        $tenant = Tenant::query()->sole();

        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($this->ownerOf($tenant)->email)->toBe('nadia@example.test')
            ->and(Subscription::forTenant($tenant)->exists())->toBeFalse()
            ->and($this->auditTrail($tenant, 'workspace.registered')->sole()->properties)->toEqual(['plan' => null]);
    });

    it('creates nothing at all when making the workspace fails half-way', function () {
        $token = ($this->register)();

        // The account and the tenant are written first; the system roles then find no permission to hold.
        DB::table('permissions')->delete();

        $this->post(($this->confirmUrl)($token))->assertServerError();

        expect(User::query()->count())->toBe(0)
            ->and(Tenant::withTrashed()->count())->toBe(0)
            ->and(TenantMembership::withoutTenancy()->count())->toBe(0)
            ->and(Subscription::withoutTenancy()->count())->toBe(0)
            ->and($this->auditTrail(null, 'platform.workspace.registered'))->toBeEmpty()
            ->and(($this->registrations)()->sole()->isWaiting())->toBeTrue();

        // The link was not used up: once the fault is gone it works.
        $this->syncPermissions();

        $this->post(($this->confirmUrl)($token))->assertRedirect();

        expect(Tenant::query()->sole()->slug)->toBe('acme-trading');
    });

    it('works once', function () {
        $token = ($this->register)();

        $this->post(($this->confirmUrl)($token))->assertRedirect();

        // Pressed again (a second tab): refused, and nothing is made twice.
        $this->post(($this->confirmUrl)($token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'invalid')->where('loginUrl', null));

        expect(Tenant::query()->count())->toBe(1)
            ->and(User::query()->count())->toBe(1)
            ->and(Subscription::forTenant(Tenant::query()->sole())->count())->toBe(1);

        // Opened again: it points at the workspace that exists instead of failing.
        $this->get(($this->confirmUrl)($token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'ready')
                ->where('token', null)
                ->where('company', 'Acme Trading')
                ->where('address', 'acme-trading.localhost')
                ->where('loginUrl', 'http://acme-trading.localhost/login'));
    });
});

describe('confirming with an address that already has an account', function () {
    beforeEach(function () {
        $this->existing = User::factory()->create(['email' => 'owner@example.test', 'name' => 'Rahim Uddin', 'locale' => 'en']);
        $this->elsewhere = $this->createTenant('elsewhere', owner: $this->existing);

        $this->token = ($this->register)([
            'name' => 'Somebody Else',
            'email' => 'Owner@Example.test',
            'password' => 'another-password',
            'password_confirmation' => 'another-password',
            'locale' => 'bn',
        ]);
    });

    it('gives the account one more workspace and changes nothing about the account', function () {
        $accounts = User::query()->count();

        // No handoff: the account signs in the ordinary way, with its own password and second factor.
        $this->post(($this->confirmUrl)($this->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Central/ConfirmRegistration')
                ->where('state', 'ready')
                ->where('token', null)
                ->where('company', 'Acme Trading')
                ->where('email', null)
                ->where('address', 'acme-trading.localhost')
                ->where('loginUrl', 'http://acme-trading.localhost/login'));

        $this->assertGuest('web');

        $account = $this->existing->fresh();

        expect(User::query()->count())->toBe($accounts)
            ->and(User::query()->where('email', 'owner@example.test')->count())->toBe(1)
            ->and($account->name)->toBe('Rahim Uddin')
            ->and($account->locale)->toBe('en')
            ->and(Hash::check('password', $account->password))->toBeTrue()
            ->and(Hash::check('another-password', $account->password))->toBeFalse();

        $tenant = Tenant::query()->where('slug', 'acme-trading')->sole();

        expect($tenant->status)->toBe(TenantStatus::Active)
            ->and($tenant->locale)->toBe('bn')
            ->and($this->ownerOf($tenant)->is($account))->toBeTrue()
            ->and($this->inTenant($tenant, fn (): array => $account->roles()->pluck('name')->all()))->toBe(['Owner'])
            // As well as the workspace it already owned.
            ->and($this->ownerOf($this->elsewhere)->is($account))->toBeTrue()
            ->and($account->memberships()->count())->toBe(2)
            ->and($this->subscriptionOf($tenant)->plan->key)->toBe('pro')
            ->and($this->auditTrail($tenant, 'workspace.registered')->sole()->actor_id)->toBe($account->id);
    });

    it('lets the account sign in to the new workspace with the password it always had', function () {
        $this->post(($this->confirmUrl)($this->token))->assertOk();

        $this->startNewBrowserSession();

        $this->post('http://acme-trading.localhost/login', ['email' => 'owner@example.test', 'password' => 'another-password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post('http://acme-trading.localhost/login', ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertRedirect('http://acme-trading.localhost')
            ->assertSessionHasNoErrors();
        $this->assertAuthenticatedAs($this->existing, 'web');
    });
});

describe('when workspaces have to be approved', function () {
    beforeEach(function () {
        app(PlatformSettings::class)->put(['registration.mode' => 'approval']);

        $this->token = ($this->register)(['plan' => 'pro']);
    });

    it('creates the workspace pending and takes nobody in', function () {
        $this->post(($this->confirmUrl)($this->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Central/ConfirmRegistration')
                ->where('state', 'pending')
                ->where('token', null)
                ->where('company', 'Acme Trading')
                ->where('address', 'acme-trading.localhost')
                ->where('loginUrl', null));

        $tenant = Tenant::query()->sole();
        $user = User::query()->sole();

        expect($tenant->status)->toBe(TenantStatus::Pending)
            ->and($tenant->isAccessible())->toBeFalse()
            ->and($this->ownerOf($tenant)->is($user))->toBeTrue()
            ->and(Hash::check('a-good-password', $user->password))->toBeTrue()
            ->and($this->subscriptionOf($tenant)->status)->toBe(SubscriptionStatus::Trialing)
            ->and($this->auditTrail(null, 'platform.workspace.registered')->sole()->properties)
            ->toEqual(['status' => 'pending', 'plan' => 'pro', 'owner' => 'nadia@example.test']);

        $this->assertGuest('web');
    });

    it('keeps the workspace shut until it is approved', function () {
        $this->post(($this->confirmUrl)($this->token))->assertOk();

        $tenant = Tenant::query()->sole();

        $this->get('http://acme-trading.localhost/login')
            ->assertForbidden()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Core/Pages/Errors/WorkspaceUnavailable')
                ->where('workspace', 'Acme Trading')
                ->where('status', 'pending'));

        $this->post('http://acme-trading.localhost/login', ['email' => 'nadia@example.test', 'password' => 'a-good-password'])
            ->assertForbidden();
        $this->assertGuest('web');

        // The link, opened again, says the same thing and offers no sign-in that would not work.
        $this->get(($this->confirmUrl)($this->token))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'pending')
                ->where('company', 'Acme Trading')
                ->where('loginUrl', null));

        $this->inCentral(fn () => app(TenantManager::class)->approve($tenant));

        $this->get('http://acme-trading.localhost/login')->assertOk();

        $this->get(($this->confirmUrl)($this->token))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('state', 'ready')
                ->where('loginUrl', 'http://acme-trading.localhost/login'));

        $this->post('http://acme-trading.localhost/login', ['email' => 'nadia@example.test', 'password' => 'a-good-password'])
            ->assertRedirect('http://acme-trading.localhost')
            ->assertSessionHasNoErrors();
    });
});

describe('clearing out old sign-ups', function () {
    it('prunes links that expired more than a day ago and sign-ups finished more than a month ago', function () {
        $tenant = $this->createTenant('acme');

        $make = fn (string $email, array $attributes): WorkspaceRegistration => WorkspaceRegistration::query()->create([
            'email' => $email,
            'name' => 'Nadia Islam',
            'password' => Hash::make('a-good-password'),
            'company' => 'Acme Trading',
            'slug' => str($email)->before('@')->toString(),
            'locale' => 'en',
            'token_hash' => hash('sha256', $email),
            'expires_at' => now()->addDay(),
            ...$attributes,
        ]);

        $make('waiting@example.test', []);
        $make('expired-an-hour-ago@example.test', ['expires_at' => now()->subHour()]);
        $make('expired-yesterday@example.test', ['expires_at' => now()->subDay()->subMinute()]);
        $make('expired-last-week@example.test', ['expires_at' => now()->subWeek()]);
        $make('completed-last-week@example.test', ['expires_at' => now()->subDays(6), 'completed_at' => now()->subWeek(), 'tenant_id' => $tenant->id]);
        $make('completed-two-months-ago@example.test', ['expires_at' => now()->subMonths(2), 'completed_at' => now()->subMonths(2), 'tenant_id' => $tenant->id]);

        $this->artisan('model:prune', ['--model' => [WorkspaceRegistration::class]])->assertSuccessful();

        expect(($this->registrations)()->pluck('email')->all())->toBe([
            'waiting@example.test',
            'expired-an-hour-ago@example.test',
            'completed-last-week@example.test',
        ]);
    });
});
