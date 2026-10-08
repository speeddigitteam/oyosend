<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Actions\MemberManager;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');
    $this->member = $this->addMember($this->acme, Role::ADMIN);
});

/*
 * In a test every host shares one session, which is the worst case in
 * production too: a session cookie that somehow reaches another workspace.
 */
describe('a session belongs to one workspace', function () {
    it('is not accepted by a workspace the person does not belong to', function () {
        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($this->member, 'web');

        $this->get('http://globex.localhost/')->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull();
    });

    it('is not accepted by another workspace even when the person is a member there too', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->member);

        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password']);

        $this->get('http://globex.localhost/team/members')->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
    });

    it('is not accepted without the workspace binding at all', function () {
        // Authenticated, but the session was never bound to a workspace.
        $this->actingAs($this->member, 'web')
            ->get('http://acme.localhost/')
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    });

    it('cannot be re-pointed by the client', function (string $via) {
        $this->addMember($this->globex, Role::ADMIN, $this->member);

        $request = $this->actingAsMember($this->acme, $this->member);

        $response = match ($via) {
            'header' => $request->withHeader('X-Tenant', $this->globex->id)->get('http://acme.localhost/'),
            'query string' => $request->get('http://acme.localhost/?tenant='.$this->globex->slug.'&tenant_id='.$this->globex->id),
            'cookie' => $request->withCookie('tenant', $this->globex->id)->get('http://acme.localhost/'),
        };

        // The tenant always comes from the hostname.
        $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'acme'));
    })->with(['header', 'query string', 'cookie']);

    it('resolves a record of the host\'s tenant, never of the session\'s tenant', function () {
        $victim = $this->membershipOf($this->globex, $this->addMember($this->globex, Role::EMPLOYEE));

        // Signed in at acme, asking acme's host for globex's membership.
        $this->actingAsMember($this->acme, $this->member)
            ->patch("http://acme.localhost/team/members/{$victim->id}", ['status' => 'suspended'])
            ->assertNotFound();

        // And asking globex's host with acme's session: turned away, nothing changed.
        $this->actingAsMember($this->acme, $this->member)
            ->patch("http://globex.localhost/team/members/{$victim->id}", ['status' => 'suspended'])
            ->assertRedirect('http://globex.localhost/login');

        expect($victim->refresh()->status)->toBe(MembershipStatus::Active);
    });
});

describe('membership is re-checked on every request', function () {
    it('signs a suspended member out on their next request', function () {
        $this->actingAsMember($this->acme, $this->member)->get('http://acme.localhost/')->assertOk();

        $this->membershipOf($this->acme, $this->member)->update(['status' => MembershipStatus::Suspended]);

        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull();
    });

    it('signs a removed member out on their next request', function () {
        $owner = $this->ownerOf($this->acme);

        $this->actingAsMember($this->acme, $this->member)->get('http://acme.localhost/team/members')->assertOk();

        $this->inTenant($this->acme, fn () => app(MemberManager::class)->remove(
            $owner,
            $this->membershipOf($this->acme, $this->member),
        ));

        $this->get('http://acme.localhost/team/members')->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    });

    it('lets a reactivated member back in only after signing in again', function () {
        $membership = $this->membershipOf($this->acme, $this->member);

        $this->actingAsMember($this->acme, $this->member);
        $membership->update(['status' => MembershipStatus::Suspended]);
        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');

        $membership->update(['status' => MembershipStatus::Active]);

        $this->get('http://acme.localhost/')->assertRedirect('http://acme.localhost/login');
        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password'])
            ->assertRedirect('http://acme.localhost');
    });

    it('keeps the other workspaces of a person working when one membership ends', function () {
        $this->addMember($this->globex, Role::EMPLOYEE, $this->member);

        $this->membershipOf($this->globex, $this->member)->update(['status' => MembershipStatus::Suspended]);

        $this->actingAsMember($this->acme, $this->member)->get('http://acme.localhost/')->assertOk();
    });

    it('notes when a member was last seen, at most every five minutes', function () {
        $membership = $this->membershipOf($this->acme, $this->member);
        expect($membership->last_seen_at)->toBeNull();

        $this->actingAsMember($this->acme, $this->member)->get('http://acme.localhost/');
        $first = $membership->refresh()->last_seen_at;
        expect($first)->not->toBeNull();

        $this->travel(2)->minutes();
        $this->get('http://acme.localhost/');
        expect($membership->refresh()->last_seen_at->equalTo($first))->toBeTrue();

        $this->travel(4)->minutes();
        $this->get('http://acme.localhost/');
        expect($membership->refresh()->last_seen_at->greaterThan($first))->toBeTrue();

        // Presence is not an audited change.
        expect($this->auditTrail($this->acme, 'tenant_membership.updated'))->toHaveCount(0);
    });
});

describe('remember me', function () {
    beforeEach(function () {
        /**
         * Sign in with "remember me", then lose the session: all that is left
         * for the next request are the two long-lived cookies - the framework's
         * recaller and the one naming the workspace it was issued in.
         *
         * @return array<string, string> cookie name => value, as the browser would send them back
         */
        $this->rememberedAt = function (Tenant $tenant): array {
            $response = $this->post($this->tenantUrl($tenant, 'login'), [
                'email' => $this->member->email,
                'password' => 'password',
                'remember' => true,
            ]);

            $recaller = $response->getCookie(Auth::guard('web')->getRecallerName());
            $workspace = $response->getCookie(EnsureTenantMember::REMEMBER_COOKIE);

            expect($recaller)->not->toBeNull()
                ->and($workspace?->getValue())->toBe($tenant->id);

            $this->startNewBrowserSession();

            return [$recaller->getName() => $recaller->getValue(), $workspace->getName() => $workspace->getValue()];
        };
    });

    it('does not set the long-lived cookie unless asked to', function () {
        $response = $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password']);

        expect($response->getCookie(Auth::guard('web')->getRecallerName()))->toBeNull()
            ->and($response->getCookie(EnsureTenantMember::REMEMBER_COOKIE))->toBeNull();
    });

    it('restores the session in the same workspace from the cookie', function () {
        $cookies = ($this->rememberedAt)($this->acme);

        $this->assertGuest('web');

        $this->withCookies($cookies)
            ->get('http://acme.localhost/team/members')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('auth.user.email', $this->member->email));

        $this->assertAuthenticatedAs($this->member, 'web');

        expect(Auth::guard('web')->viaRemember())->toBeTrue()
            ->and(session(EnsureTenantMember::SESSION_KEY))->toBe($this->acme->id);

        // From here on it is an ordinary bound session.
        $this->get('http://acme.localhost/')->assertOk();
    });

    it('does not restore a session in a workspace the person does not belong to', function () {
        $cookies = ($this->rememberedAt)($this->acme);

        $this->withCookies($cookies)
            ->get('http://globex.localhost/')
            ->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull();
    });

    it('does not restore a session in another workspace the person also belongs to', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->member);
        $cookies = ($this->rememberedAt)($this->acme);

        // A copy of acme's cookies presented to globex: the person is a member
        // there too, but never proved who they are to globex.
        $this->withCookies($cookies)
            ->get('http://globex.localhost/')
            ->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
        expect(session(EnsureTenantMember::SESSION_KEY))->toBeNull();
    });

    it('does not restore a session from the recaller cookie alone, or with a forged workspace', function (?string $workspace) {
        $cookies = ($this->rememberedAt)($this->acme);
        $recaller = Auth::guard('web')->getRecallerName();

        $forged = [$recaller => $cookies[$recaller]];

        if ($workspace !== null) {
            $forged[EnsureTenantMember::REMEMBER_COOKIE] = $workspace;
        }

        $this->withCookies($forged)
            ->get('http://acme.localhost/')
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    })->with([
        'no workspace cookie' => [null],
        'an empty one' => [''],
        'another workspace' => ['01hzzzzzzzzzzzzzzzzzzzzzzz'],
    ]);

    it('does not restore a session for a member who has been suspended since', function () {
        $cookies = ($this->rememberedAt)($this->acme);

        $this->membershipOf($this->acme, $this->member)->update(['status' => MembershipStatus::Suspended]);

        $this->withCookies($cookies)
            ->get('http://acme.localhost/')
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    });

    it('does not restore a session after the password was changed elsewhere', function () {
        $cookies = ($this->rememberedAt)($this->acme);

        $this->member->forceFill(['password' => 'a-brand-new-password'])->save();

        $this->withCookies($cookies)
            ->get('http://acme.localhost/')
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    });

    it('does not restore a session after signing out', function () {
        $this->post('http://acme.localhost/login', ['email' => $this->member->email, 'password' => 'password', 'remember' => true]);

        $name = Auth::guard('web')->getRecallerName();
        $value = $this->member->id.'|'.$this->member->refresh()->remember_token.'|'.$this->member->password;

        $this->post('http://acme.localhost/logout');
        $this->startNewBrowserSession();

        // Signing out rotates the token, so a copy of the old cookie is dead.
        $this->withCookie($name, $value)->withCookie(EnsureTenantMember::REMEMBER_COOKIE, $this->acme->id)
            ->get('http://acme.localhost/')
            ->assertRedirect('http://acme.localhost/login');

        $this->assertGuest('web');
    });

    it('does not let the cookie override a session bound to another workspace', function () {
        $this->addMember($this->globex, Role::ADMIN, $this->member);
        $cookies = ($this->rememberedAt)($this->acme);

        // A live session that belongs to acme, presented to globex together with the cookie.
        $this->actingAsMember($this->acme, $this->member)
            ->withCookies($cookies)
            ->get('http://globex.localhost/')
            ->assertRedirect('http://globex.localhost/login');

        $this->assertGuest('web');
    });
});
