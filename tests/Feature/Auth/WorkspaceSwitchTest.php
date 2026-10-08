<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Identity\Http\Middleware\EnsureTenantMember;
use App\Core\Tenancy\Enums\TenantStatus;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    // Handoff tokens live in the cache. On a store that is shared between
    // tenants the test also proves they are kept out of each tenant's namespace.
    $this->useDatabaseCache();

    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex']);
    $this->initech = $this->createTenant('initech', ['name' => 'Initech']);

    // In acme and globex, not in initech.
    $this->member = $this->addMember($this->acme, Role::MANAGER);
    $this->addMember($this->globex, Role::EMPLOYEE, $this->member);

    /** Ask acme for a handoff to another workspace; returns the URL it redirects to. */
    $this->handoffTo = function (string $slug): string {
        $response = $this->actingAsMember($this->acme, $this->member)
            ->post("http://acme.localhost/workspaces/{$slug}/switch");

        $response->assertRedirect();

        return (string) $response->headers->get('Location');
    };
});

it('lists the workspaces a person can switch to', function () {
    $this->actingAsMember($this->acme, $this->member)
        ->get('http://acme.localhost/')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('workspaces', [
            ['name' => 'Acme Trading', 'slug' => 'acme', 'current' => true],
            ['name' => 'Globex', 'slug' => 'globex', 'current' => false],
        ]));
});

it('leaves out workspaces that are suspended, deleted or where the membership is suspended', function (string $case) {
    match ($case) {
        'suspended workspace' => $this->globex->update(['status' => TenantStatus::Suspended]),
        'deleted workspace' => $this->globex->delete(),
        'suspended membership' => $this->membershipOf($this->globex, $this->member)->update(['status' => MembershipStatus::Suspended]),
    };

    $this->actingAsMember($this->acme, $this->member)
        ->get('http://acme.localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('workspaces', [
            ['name' => 'Acme Trading', 'slug' => 'acme', 'current' => true],
        ]));
})->with(['suspended workspace', 'deleted workspace', 'suspended membership']);

it('hands a member over to another of their workspaces without a second sign-in', function () {
    $url = ($this->handoffTo)('globex');

    expect($url)->toMatch('#^http://globex\.localhost/auth/handoff\?token=[A-Za-z0-9]{64}$#');

    // The other workspace is a different origin, hence a different browser session.
    $this->startNewBrowserSession();

    $this->get($url)->assertRedirect('http://globex.localhost');

    $this->assertAuthenticatedAs($this->member, 'web');
    expect(session(EnsureTenantMember::SESSION_KEY))->toBe($this->globex->id);

    $this->get('http://globex.localhost/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'globex'));

    expect($this->auditTrail($this->globex, 'auth.login')->sole()->properties)->toBe(['method' => 'workspace_switch']);
});

it('answers an Inertia visit with a full-page redirect to the other origin', function () {
    $this->actingAsMember($this->acme, $this->member)
        ->withHeader('X-Inertia', 'true')
        ->post('http://acme.localhost/workspaces/globex/switch')
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location');
});

it('accepts a handoff token once only', function () {
    $url = ($this->handoffTo)('globex');

    $this->startNewBrowserSession();
    $this->get($url)->assertRedirect('http://globex.localhost');

    $this->startNewBrowserSession();
    $this->get($url)->assertRedirect('http://globex.localhost/login');

    $this->assertGuest('web');
});

it('accepts a handoff token only in the workspace it was issued for', function () {
    $token = explode('token=', ($this->handoffTo)('globex'))[1];

    $this->startNewBrowserSession();

    // A workspace the person belongs to, and one they do not.
    $this->get("http://acme.localhost/auth/handoff?token={$token}")->assertRedirect('http://acme.localhost/login');
    $this->assertGuest('web');

    $this->get("http://initech.localhost/auth/handoff?token={$token}")->assertRedirect('http://initech.localhost/login');
    $this->assertGuest('web');
});

it('expires a handoff token after a minute', function () {
    $url = ($this->handoffTo)('globex');

    $this->startNewBrowserSession();
    $this->travel(config('platform.workspace_handoff_ttl') + 1)->seconds();

    $this->get($url)->assertRedirect('http://globex.localhost/login');
    $this->assertGuest('web');
});

it('ignores a missing, malformed or made-up token', function (string $query) {
    $this->get('http://globex.localhost/auth/handoff'.$query)->assertRedirect('http://globex.localhost/login');

    $this->assertGuest('web');
})->with([
    'missing' => [''],
    'empty' => ['?token='],
    'too short' => ['?token=abc'],
    'wrong characters' => ['?token='.str_repeat('-', 64)],
    'well formed but unknown' => ['?token='.str_repeat('a', 64)],
    'an array' => ['?token[]=a'],
]);

it('refuses a handoff when the membership ended between issue and use', function () {
    $url = ($this->handoffTo)('globex');

    $this->membershipOf($this->globex, $this->member)->update(['status' => MembershipStatus::Suspended]);

    $this->startNewBrowserSession();
    $this->get($url)->assertRedirect('http://globex.localhost/login');

    $this->assertGuest('web');
});

it('will not issue a handoff to a workspace the person cannot enter', function (string $case) {
    $slug = match ($case) {
        'not a member' => 'initech',
        'unknown workspace' => 'nowhere',
        'reserved name' => 'admin',
        default => 'globex',
    };

    match ($case) {
        'suspended membership' => $this->membershipOf($this->globex, $this->member)->update(['status' => MembershipStatus::Suspended]),
        'suspended workspace' => $this->globex->update(['status' => TenantStatus::Suspended]),
        'deleted workspace' => $this->globex->delete(),
        default => null,
    };

    // All answered alike: whether the workspace exists is not disclosed.
    $this->actingAsMember($this->acme, $this->member)
        ->post("http://acme.localhost/workspaces/{$slug}/switch")
        ->assertNotFound();
})->with(['not a member', 'unknown workspace', 'reserved name', 'suspended membership', 'suspended workspace', 'deleted workspace']);

it('needs a signed-in member to issue a handoff', function () {
    $this->post('http://acme.localhost/workspaces/globex/switch')->assertRedirect('http://acme.localhost/login');
});

it('keeps handoff tokens out of every tenant\'s cache namespace', function () {
    $token = explode('token=', ($this->handoffTo)('globex'))[1];
    $key = 'workspace-handoff:'.hash('sha256', $token);

    // Stored by its hash, centrally: reachable from the target, invisible to tenant caches.
    expect(app('cache.central')->has($key))->toBeTrue()
        ->and(app('cache.central')->has('workspace-handoff:'.$token))->toBeFalse()
        ->and($this->inTenant($this->acme, fn (): bool => Cache::has($key)))->toBeFalse()
        ->and($this->inTenant($this->globex, fn (): bool => Cache::has($key)))->toBeFalse();

    $this->startNewBrowserSession();
    $this->get("http://globex.localhost/auth/handoff?token={$token}")->assertRedirect('http://globex.localhost');
});
