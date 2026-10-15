<?php

declare(strict_types=1);

use App\Core\Communication\Contracts\MarketingAudience;
use App\Core\Communication\Data\MarketingChannel;
use App\Core\Contacts\Models\Contact;
use App\Core\Entitlements\Contracts\EntitlementChecker;
use App\Core\Modules\ModuleRegistry;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Communication\Actions\SuppressionManager;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use App\Modules\Communication\Models\Consent;
use App\Modules\EmailMarketing\Actions\ListManager;
use App\Modules\EmailMarketing\Actions\PublicSignup;
use App\Modules\EmailMarketing\Actions\SubscriberManager;
use App\Modules\EmailMarketing\Enums\ListStatus;
use App\Modules\EmailMarketing\Enums\SubscriptionSource;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use App\Modules\EmailMarketing\Mail\SignupConfirmationMail;
use App\Modules\EmailMarketing\Models\MailingList;
use App\Modules\EmailMarketing\Models\SignupRequest;
use App\Modules\EmailMarketing\Models\Subscription;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Fixtures\Flow\RevocableEntitlements;
use Tests\Support\InteractsWithEmailMarketing;

uses(InteractsWithEmailMarketing::class);

beforeEach(function () {
    Mail::fake();
    $this->tenant = $this->emTenant('em-join');
    $this->list = $this->emList($this->tenant, 'Weekly news');
    $this->token = $this->inTenant($this->tenant, fn () => app(ListManager::class)->configureSignup($this->list, true, false, false)->signup_token);

    $this->join = fn (?Tenant $tenant = null, ?string $token = null, string $suffix = ''): string => $this->tenantUrl($tenant ?? $this->tenant, '/email-marketing/join/'.($token ?? $this->token).$suffix);
    $this->confirmUrl = fn (string $token, ?Tenant $tenant = null): string => $this->tenantUrl($tenant ?? $this->tenant, '/email-marketing/join/confirm/'.$token);

    // Open the form, let a person's worth of seconds pass, post it.
    $this->submit = function (array $data, ?Tenant $tenant = null, ?string $token = null, bool $wait = true) {
        $html = $this->get(($this->join)($tenant, $token))->getContent();
        preg_match('/name="t" value="([^"]+)"/', (string) $html, $match);

        if ($wait) {
            $this->travel(5)->seconds();
        }

        return $this->post(($this->join)($tenant, $token), ['t' => $match[1] ?? '', ...$data]);
    };

    $this->tokenOfMail = function (): string {
        $mail = Mail::queued(SignupConfirmationMail::class)->last();
        $property = new ReflectionProperty($mail, 'token');

        return (string) $property->getValue($mail);
    };

    $this->count = fn (string $model): int => $this->inTenant($this->tenant, fn () => $model::query()->count());
    $this->contactsOf = fn (string $email): int => $this->inTenant($this->tenant, fn () => Contact::query()->whereRaw('lower(email) = ?', [$email])->count());
});

it('serves a stateless, hardened form that cannot be framed by default', function () {
    $response = $this->get(($this->join)())->assertOk();

    expect($response->getContent())->toContain('Weekly news')->and($response->getContent())->toContain('name="website_url"')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->getCookies())->toBeEmpty();
});

it('answers 404 for an unknown, malformed, disabled or archived form', function () {
    $this->get(($this->join)(null, str_repeat('a', 32)))->assertNotFound();
    $this->get(($this->join)(null, 'short'))->assertNotFound();

    $this->inTenant($this->tenant, fn () => app(ListManager::class)->configureSignup($this->list->refresh(), false, false, false));
    $this->get(($this->join)())->assertNotFound();

    $this->inTenant($this->tenant, fn () => app(ListManager::class)->configureSignup($this->list->refresh(), true, false, false));
    $this->get(($this->join)())->assertOk();
    $this->inTenant($this->tenant, fn () => $this->list->refresh()->forceFill(['status' => ListStatus::Archived])->save());
    $this->get(($this->join)())->assertNotFound();
});

it('queues one confirmation email and creates no contact and no membership before the link is confirmed', function () {
    $response = ($this->submit)(['email' => 'New.Person@Example.test', 'first_name' => 'Nadia', 'last_name' => 'Akter'])->assertOk();

    expect($response->getContent())->toContain('Check your inbox');
    Mail::assertQueued(SignupConfirmationMail::class, 1);
    Mail::assertQueued(SignupConfirmationMail::class, fn (SignupConfirmationMail $mail) => $mail->hasTo('new.person@example.test'));

    expect(($this->contactsOf)('new.person@example.test'))->toBe(0)
        ->and(($this->count)(Subscription::class))->toBe(0)
        ->and(($this->count)(SignupRequest::class))->toBe(1);

    // Only the hash of the token is stored.
    $token = ($this->tokenOfMail)();
    $stored = $this->inTenant($this->tenant, fn () => SignupRequest::query()->sole());
    expect($stored->token_hash)->toBe(hash('sha256', $token))->and(strlen($token))->toBe(64);
});

it('does not subscribe on a GET, so a link scanner opts nobody in', function () {
    ($this->submit)(['email' => 'scanned@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();

    $page = $this->get(($this->confirmUrl)($token))->assertOk();
    $this->get(($this->confirmUrl)($token))->assertOk();

    expect($page->getContent())->toContain('Confirm subscription')->and($page->getContent())->toContain('method="post"')
        ->and($page->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and(($this->contactsOf)('scanned@example.test'))->toBe(0)
        ->and(($this->count)(Subscription::class))->toBe(0)
        ->and(($this->count)(SignupRequest::class))->toBe(1);
});

it('subscribes on the confirming POST with double opt-in evidence, once, and the link is then dead', function () {
    ($this->submit)(['email' => 'nadia@example.test', 'first_name' => 'Nadia', 'last_name' => 'Akter'])->assertOk();
    $token = ($this->tokenOfMail)();

    $this->withHeaders(['User-Agent' => 'PestBrowser/1.0'])->post(($this->confirmUrl)($token))->assertOk()->assertSee('You are subscribed');

    $this->inTenant($this->tenant, function (): void {
        $contact = Contact::query()->where('email', 'nadia@example.test')->sole();
        $member = Subscription::query()->sole();

        expect($contact->first_name)->toBe('Nadia')->and($contact->last_name)->toBe('Akter')
            ->and($member->contact_id)->toBe($contact->id)
            ->and($member->status)->toBe(SubscriptionStatus::Subscribed)
            ->and($member->source)->toBe(SubscriptionSource::Form->value)
            ->and($member->consent_method)->toBe('website_form')
            ->and($member->consent_evidence)->toContain('Double opt-in')->toContain($this->list->id)
            ->and($member->consent_evidence)->not->toMatch('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
            ->and($member->consent_evidence)->toContain(substr(hash('sha256', 'PestBrowser/1.0'), 0, 16))
            ->and(SignupRequest::query()->count())->toBe(0);

        // The platform consent was recorded in the same transaction, so the address is sendable right away.
        $consent = Consent::query()->where('contact_id', $contact->id)->where('channel', 'email')->sole();
        expect($consent->status->value)->toBe('opted_in')
            ->and($consent->method)->toBe('form')
            ->and($consent->evidence)->toContain('Double opt-in via form '.$this->list->id)->toContain('user agent hash '.substr(hash('sha256', 'PestBrowser/1.0'), 0, 16))
            ->and($consent->evidence)->not->toMatch('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/')
            ->and(app(MarketingAudience::class)->canReceive($contact->id, MarketingChannel::Email)->allowed)->toBeTrue();
    });

    // Replay, and a second click from a scanner after the person: the same bare 404 page.
    $this->post(($this->confirmUrl)($token))->assertNotFound();
    $this->get(($this->confirmUrl)($token))->assertNotFound();
    expect(($this->count)(Subscription::class))->toBe(1);
});

it('refuses an expired confirmation link and prunes it later', function () {
    ($this->submit)(['email' => 'late@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();

    $this->travel(PublicSignup::TTL_DAYS * 24 * 60 + 5)->minutes();
    $this->get(($this->confirmUrl)($token))->assertNotFound();
    $this->post(($this->confirmUrl)($token))->assertNotFound();
    expect(($this->contactsOf)('late@example.test'))->toBe(0)->and(($this->count)(Subscription::class))->toBe(0);

    $this->travel(2)->days();
    expect($this->inTenant($this->tenant, fn () => app(PublicSignup::class)->prune()))->toBe(1)
        ->and(($this->count)(SignupRequest::class))->toBe(0);
});

it('reuses an existing contact and never duplicates it or overwrites its name', function () {
    $contact = $this->emContact($this->tenant, ['email' => 'Known@Example.test', 'first_name' => 'Karim', 'last_name' => 'Hossain']);

    ($this->submit)(['email' => 'known@example.test', 'first_name' => 'Someone', 'last_name' => 'Else'])->assertOk();
    $this->post(($this->confirmUrl)(($this->tokenOfMail)()))->assertOk()->assertSee('You are subscribed');

    $this->inTenant($this->tenant, function () use ($contact): void {
        expect(Contact::query()->whereRaw('lower(email) = ?', ['known@example.test'])->count())->toBe(1)
            ->and(Subscription::query()->sole()->contact_id)->toBe($contact->id)
            ->and($contact->refresh()->first_name)->toBe('Karim')
            ->and($contact->last_name)->toBe('Hossain');
    });
});

it('is quiet for a member who is already subscribed and does not send another email', function () {
    $contact = $this->emContact($this->tenant, ['email' => 'in@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $contact);

    ($this->submit)(['email' => 'in@example.test'])->assertOk()->assertSee('Check your inbox');

    Mail::assertNothingQueued();
});

it('looks the same to the visitor whatever the address is', function () {
    $known = $this->emContact($this->tenant, ['email' => 'same@example.test']);
    $this->emSubscribe($this->tenant, $this->list, $known);

    $a = ($this->submit)(['email' => 'same@example.test'])->assertOk()->getContent();
    $b = ($this->submit)(['email' => 'fresh@example.test'])->assertOk()->getContent();

    expect(preg_replace('/\s+/', ' ', (string) $a))->toBe(preg_replace('/\s+/', ' ', (string) $b));
});

it('sends at most one confirmation per address per list per hour', function () {
    ($this->submit)(['email' => 'bomb@example.test'])->assertOk();
    ($this->submit)(['email' => 'bomb@example.test'])->assertOk();
    ($this->submit)(['email' => 'BOMB@example.test'])->assertOk();

    Mail::assertQueued(SignupConfirmationMail::class, 1);
    expect(($this->count)(SignupRequest::class))->toBe(1);

    $this->travel(61)->minutes();
    ($this->submit)(['email' => 'bomb@example.test'])->assertOk();

    Mail::assertQueued(SignupConfirmationMail::class, 2);
});

it('answers a bot that fills the honeypot with the normal page and records nothing', function () {
    $response = ($this->submit)(['email' => 'bot@example.test', 'website_url' => 'https://spam.example'])->assertOk();

    expect($response->getContent())->toContain('Check your inbox');
    Mail::assertNothingQueued();
    expect(($this->count)(SignupRequest::class))->toBe(0);
});

it('turns away a post that came too fast, was never issued by the form or was tampered with', function () {
    ($this->submit)(['email' => 'fast@example.test'], wait: false)->assertStatus(422)->assertSee('Please wait a moment');
    $this->post(($this->join)(), ['email' => 'nokey@example.test'])->assertStatus(422);
    $this->travel(10)->seconds();
    $this->post(($this->join)(), ['email' => 'forged@example.test', 't' => (time() - 60).'.'.str_repeat('a', 64)])->assertStatus(422);

    // A value signed for another workspace's form is not accepted here.
    $other = $this->emTenant('em-join-other');
    $otherList = $this->emList($other, 'Other');
    $otherToken = $this->inTenant($other, fn () => app(ListManager::class)->configureSignup($otherList, true, false, false)->signup_token);
    $html = $this->get(($this->join)($other, $otherToken))->getContent();
    preg_match('/name="t" value="([^"]+)"/', (string) $html, $match);
    $this->travel(5)->seconds();
    $this->post(($this->join)(), ['email' => 'x@example.test', 't' => $match[1]])->assertStatus(422);

    Mail::assertNothingQueued();
});

it('throttles posts per visitor per minute and recovers', function () {
    // The hourly per-visitor limit is tested below; here only the route's 30 a minute is reached.
    config(['email_marketing.signup.per_hour' => 1000]);
    $statuses = [];

    // Inside one minute: only the first waits out the time trap (later ones are refused 422 until the throttle answers).
    for ($i = 1; $i <= 32; $i++) {
        $statuses[] = ($this->submit)(['email' => "user{$i}@example.test"], wait: $i === 1)->getStatusCode();
    }

    expect($statuses[0])->toBe(200)->and($statuses)->toContain(429);

    $this->travel(2)->minutes();
    ($this->submit)(['email' => 'again@example.test'])->assertOk();
});

it('limits one visitor to a few submissions per hour', function () {
    config(['email_marketing.signup.per_hour' => 2]);

    ($this->submit)(['email' => 'h1@example.test'])->assertOk();
    ($this->submit)(['email' => 'h2@example.test'])->assertOk();
    ($this->submit)(['email' => 'h3@example.test'])->assertStatus(429)->assertSee('Too many requests');

    expect(($this->count)(SignupRequest::class))->toBe(2);

    $this->travel(61)->minutes();
    ($this->submit)(['email' => 'h3@example.test'])->assertOk();
});

it('keeps the sign-ups and links of one workspace out of another', function () {
    $this->assertTenantIsolated(SignupRequest::class, function (Tenant $tenant) {
        $list = MailingList::query()->create(['name' => 'L '.Str::random(6), 'status' => ListStatus::Active]);

        return SignupRequest::query()->create([
            'list_id' => $list->id, 'email' => 'a'.Str::random(6).'@example.test', 'token_hash' => hash('sha256', Str::random(20)),
            'expires_at' => now()->addDay(),
        ]);
    });

    ($this->submit)(['email' => 'iso@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();
    $other = $this->emTenant('em-join-intruder');

    // The form token and the confirmation token of one workspace are nothing on another's host.
    $this->get(($this->join)($other))->assertNotFound();
    $this->get(($this->confirmUrl)($token, $other))->assertNotFound();
    $this->post(($this->confirmUrl)($token, $other))->assertNotFound();

    expect(($this->count)(Subscription::class))->toBe(0)->and(($this->count)(SignupRequest::class))->toBe(1);
    $this->post(($this->confirmUrl)($token))->assertOk()->assertSee('You are subscribed');
});

it('never resubscribes a person who left without a fresh confirmation by the mailbox owner', function () {
    $contact = $this->emContact($this->tenant, ['email' => 'left@example.test']);
    $member = $this->emSubscribe($this->tenant, $this->list, $contact);
    $this->inTenant($this->tenant, fn () => app(SubscriberManager::class)->unsubscribe($member, 'asked_to_leave'));

    // Submitting the form changes nothing about the membership, it only sends the confirmation link.
    ($this->submit)(['email' => 'left@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();
    $this->get(($this->confirmUrl)($token))->assertOk();
    $this->inTenant($this->tenant, fn () => expect(Subscription::query()->sole()->status)->toBe(SubscriptionStatus::Unsubscribed));

    // Only the explicit POST on the link reopens it, with new evidence.
    $this->post(($this->confirmUrl)($token))->assertOk()->assertSee('You are subscribed');
    $this->inTenant($this->tenant, function (): void {
        $member = Subscription::query()->sole();
        expect($member->status)->toBe(SubscriptionStatus::Subscribed)
            ->and($member->unsubscribed_at)->toBeNull()
            ->and($member->consent_evidence)->toContain('Double opt-in');
    });
});

it('honours the platform suppression and opt-out and leaves nothing behind', function () {
    // A bounced known address is never mailed, not even the confirmation.
    $bounced = $this->emContact($this->tenant, ['email' => 'bounced@example.test']);
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(Channel::Email, 'bounced@example.test', SuppressionScope::All, SuppressionReason::Bounced, $bounced->id));
    ($this->submit)(['email' => 'bounced@example.test'])->assertOk()->assertSee('Check your inbox');
    Mail::assertNothingQueued();

    // An unknown address that is suppressed by address: the mail goes out (we cannot tell), the confirm is refused,
    // the contact is not created and the link is spent.
    $this->inTenant($this->tenant, fn () => app(SuppressionManager::class)->suppress(Channel::Email, 'ghost@example.test', SuppressionScope::All, SuppressionReason::Bounced));
    ($this->submit)(['email' => 'ghost@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();
    $this->post(($this->confirmUrl)($token))->assertOk()->assertSee('We could not add this address');

    expect(($this->contactsOf)('ghost@example.test'))->toBe(0)
        ->and(($this->count)(Subscription::class))->toBe(0)
        ->and(($this->count)(SignupRequest::class))->toBe(0);
    $this->post(($this->confirmUrl)($token))->assertNotFound();

    // Unsubscribed through the platform link (marketing-scope suppression): the list state is not reopened.
    $left = $this->emContact($this->tenant, ['email' => 'unsub@example.test']);
    $member = $this->emSubscribe($this->tenant, $this->list, $left);
    $this->inTenant($this->tenant, function () use ($left, $member): void {
        app(SuppressionManager::class)->suppress(Channel::Email, 'unsub@example.test', SuppressionScope::Marketing, SuppressionReason::Unsubscribed, $left->id);
        app(SubscriberManager::class)->unsubscribe($member, 'unsubscribed');
    });
    ($this->submit)(['email' => 'unsub@example.test'])->assertOk();
    $this->post(($this->confirmUrl)(($this->tokenOfMail)()))->assertOk()->assertSee('We could not add this address');
    $this->inTenant($this->tenant, fn () => expect(Subscription::query()->where('contact_id', $left->id)->sole()->status)->toBe(SubscriptionStatus::Unsubscribed));
});

it('keeps hostile text inert in the form, in errors and in stored names', function () {
    $script = '<script>alert(1)</script>';

    $named = ($this->submit)(['email' => 'x@example.test', 'first_name' => $script])->assertStatus(422);
    expect($named->getContent())->not->toContain('<script>alert')->and($named->getContent())->toContain('&lt;script&gt;');

    $email = ($this->submit)(['email' => '"><img src=x onerror=alert(1)>'])->assertStatus(422);
    expect($email->getContent())->not->toContain('<img src=x')->and($email->getContent())->toContain('&quot;&gt;&lt;img');

    $quote = ($this->submit)(['email' => 'q@example.test', 'first_name' => 'x" onfocus="alert(1)', 'last_name' => 'y'])->assertOk();
    expect($quote->getContent())->not->toContain('onfocus');
    $this->post(($this->confirmUrl)(($this->tokenOfMail)()))->assertOk();

    // The stored name is data: the form and the confirmation never print it, and the mail has no name at all.
    $this->inTenant($this->tenant, fn () => expect(Contact::query()->where('email', 'q@example.test')->sole()->first_name)->toBe('x" onfocus="alert(1)'));
    $this->get(($this->join)())->assertDontSee('onfocus');

    // A title with markup is escaped in the page and in the mail.
    $this->inTenant($this->tenant, fn () => $this->list->refresh()->forceFill(['name' => '<b>Hot</b> list'])->save());
    $this->get(($this->join)())->assertDontSee('<b>Hot</b>', false)->assertSee('&lt;b&gt;Hot&lt;/b&gt;', false);
});

it('can be framed only when embedding was switched on, and never on the confirmation pages', function () {
    $this->inTenant($this->tenant, fn () => app(ListManager::class)->configureSignup($this->list->refresh(), true, true, false));

    $form = $this->get(($this->join)())->assertOk();
    expect($form->headers->get('X-Frame-Options'))->toBeNull()
        ->and($form->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *')->toContain("default-src 'none'");

    ($this->submit)(['email' => 'framed@example.test'])->assertOk();
    $confirm = $this->get(($this->confirmUrl)(($this->tokenOfMail)()))->assertOk();
    expect($confirm->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($confirm->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");

    // Switching the form off also switches framing off.
    $this->inTenant($this->tenant, fn () => app(ListManager::class)->configureSignup($this->list->refresh(), false, true, false));
    expect($this->inTenant($this->tenant, fn () => $this->list->refresh()->signup_embed))->toBeFalse();
});

it('stops with the app: the form and the links are 404 while the workspace is not entitled, and work again after', function () {
    $checker = new RevocableEntitlements($this->app->make(ModuleRegistry::class));
    $checker->grant($this->tenant, 'communication', 'email_marketing');
    $this->app->instance(EntitlementChecker::class, $checker);
    $this->app->forgetScopedInstances();

    ($this->submit)(['email' => 'lapse@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();

    $checker->revoke($this->tenant, 'email_marketing');
    $this->app->forgetScopedInstances();

    $this->get(($this->join)())->assertNotFound();
    $this->post(($this->join)(), ['email' => 'x@example.test'])->assertNotFound();
    $this->get(($this->confirmUrl)($token))->assertNotFound();
    $this->post(($this->confirmUrl)($token))->assertNotFound();
    expect(($this->contactsOf)('lapse@example.test'))->toBe(0);

    $checker->grant($this->tenant, 'email_marketing');
    $this->app->forgetScopedInstances();
    $this->post(($this->confirmUrl)($token))->assertOk()->assertSee('You are subscribed');
});

it('rolls the membership back when the platform consent cannot be recorded', function () {
    $checker = new RevocableEntitlements($this->app->make(ModuleRegistry::class));
    $checker->grant($this->tenant, 'communication', 'email_marketing');
    $this->app->instance(EntitlementChecker::class, $checker);
    $this->app->forgetScopedInstances();

    ($this->submit)(['email' => 'nocomm@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();

    $checker->revoke($this->tenant, 'communication');
    $this->app->forgetScopedInstances();

    $this->post(($this->confirmUrl)($token))->assertOk()->assertSee('We could not add this address');

    expect(($this->contactsOf)('nocomm@example.test'))->toBe(0)
        ->and(($this->count)(Subscription::class))->toBe(0)
        ->and(($this->count)(Consent::class))->toBe(0);
});

it('keeps the link alive when the plan cannot take another subscriber, and says so', function () {
    ($this->submit)(['email' => 'cap@example.test'])->assertOk();
    $token = ($this->tokenOfMail)();

    $plan = $this->createFreePlan(['key' => 'em-join-zero', 'modules' => ['email_marketing', 'communication'], 'limits' => ['email_marketing.subscribers' => 0]]);
    $this->subscribe($this->tenant, $plan, withTrial: false);

    $this->post(($this->confirmUrl)($token))->assertStatus(503)->assertSee('Please try again later');
    expect(($this->contactsOf)('cap@example.test'))->toBe(0)->and(($this->count)(Subscription::class))->toBe(0)->and(($this->count)(SignupRequest::class))->toBe(1);
});

it('lets a list manager configure the form and shows the link, the snippet and the numbers', function () {
    [$manager] = $this->emMember($this->tenant, ['email_marketing.lists.view', 'email_marketing.lists.manage']);
    [$viewer] = $this->emMember($this->tenant, ['email_marketing.lists.view']);
    $url = $this->emUrl($this->tenant, "/lists/{$this->list->id}");

    // A viewer can neither configure nor see the link.
    $this->actingAsMember($this->tenant, $viewer);
    $this->put($url.'/signup', ['enabled' => true, 'embed' => false])->assertForbidden();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('signup', null));

    $this->actingAsMember($this->tenant, $manager);
    $this->put($url.'/signup', ['enabled' => true, 'embed' => true])->assertRedirect();

    $first = $this->inTenant($this->tenant, fn () => $this->list->refresh()->signup_token);
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('signup.enabled', true)
        ->where('signup.embed', true)
        ->where('signup.url', fn (string $value) => str_ends_with($value, '/email-marketing/join/'.$first))
        ->where('signup.iframe', fn (string $value) => str_starts_with($value, '<iframe src="http') && str_contains($value, $first))
        ->where('signup.confirmed', 0)
        ->where('signup.unconfirmed', 0));

    // Replacing the link kills the old one.
    $this->put($url.'/signup', ['enabled' => true, 'embed' => true, 'rotate' => true])->assertRedirect();
    $second = $this->inTenant($this->tenant, fn () => $this->list->refresh()->signup_token);
    expect($second)->not->toBe($first);
    $this->get(($this->join)(null, $first))->assertNotFound();
    $this->get(($this->join)(null, $second))->assertOk();

    // Off: no link in the props, no iframe.
    $this->put($url.'/signup', ['enabled' => false, 'embed' => true])->assertRedirect();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('signup.enabled', false)->where('signup.url', null)->where('signup.iframe', null)->where('signup.embed', false));

    $this->put($url.'/signup', ['enabled' => 'maybe'])->assertSessionHasErrors('enabled');
    $this->assertNotFoundFromOtherTenant('PUT', "/email-marketing/lists/{$this->list->id}/signup", $this->tenant, ['enabled' => true, 'embed' => false]);
});

it('counts confirmed and waiting sign-ups on the list page', function () {
    [$manager] = $this->emMember($this->tenant, ['email_marketing.lists.view', 'email_marketing.lists.manage']);

    ($this->submit)(['email' => 'one@example.test'])->assertOk();
    $this->post(($this->confirmUrl)(($this->tokenOfMail)()))->assertOk();
    ($this->submit)(['email' => 'two@example.test'])->assertOk();

    $this->actingAsMember($this->tenant, $manager);
    $this->get($this->emUrl($this->tenant, "/lists/{$this->list->id}"))->assertInertia(fn (Assert $page) => $page
        ->where('signup.confirmed', 1)
        ->where('signup.unconfirmed', 1));
});
