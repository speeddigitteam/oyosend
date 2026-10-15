<?php

declare(strict_types=1);

use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\Models\Domain;
use App\Core\Tenancy\TenantContext;
use Inertia\Testing\AssertableInertia;

it('resolves the workspace from its subdomain', function () {
    $tenant = $this->createTenant('acme', ['name' => 'Acme Trading']);

    $this->get('http://acme.localhost/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Auth/Login')
            ->where('workspace', 'Acme Trading')
            ->where('app.area', 'tenant')
            ->where('tenant.id', $tenant->id)
            ->where('tenant.slug', 'acme'));

    expect(app(TenantContext::class)->id())->toBe($tenant->id);
});

it('resolves the workspace whatever the case of the hostname', function () {
    $this->createTenant('acme');

    $this->get('http://ACME.LOCALHOST/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'acme'));
});

it('answers 404 for a subdomain no workspace owns', function () {
    $this->createTenant('acme');

    $this->get('http://nobody.localhost/login')
        ->assertNotFound()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Errors/Error')
            ->where('status', 404));

    $this->get('http://nobody.localhost/')->assertNotFound();
    $this->post('http://nobody.localhost/login', ['email' => 'a@b.test', 'password' => 'x'])->assertNotFound();
});

it('answers 404 for a workspace that has been deleted', function () {
    $tenant = $this->createTenant('acme');
    $tenant->delete();

    $this->get('http://acme.localhost/login')->assertNotFound();
});

it('never serves a workspace on a reserved subdomain', function (string $label) {
    $tenant = $this->createTenant('acme');

    // Even if a domain row for the label were to exist, bypassing the provisioner.
    $tenant->domains()->create(['domain' => $label, 'type' => Domain::TYPE_SUBDOMAIN]);

    $this->get("http://{$label}.localhost/login")->assertNotFound();
    $this->get("http://{$label}.localhost/")->assertNotFound();
})->with(['www', 'api', 'app', 'horizon', 'mail']);

it('never resolves a tenant on the central host', function () {
    $tenant = $this->createTenant('acme');
    $tenant->domains()->create(['domain' => 'localhost', 'type' => Domain::TYPE_CUSTOM]);

    $this->get('http://localhost/register')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Central/Register')
            ->where('app.area', 'central')
            ->where('domain', 'localhost')
            ->missing('tenant')
            ->missing('auth'));

    expect(app(TenantContext::class)->check())->toBeFalse();
});

it('never resolves a tenant on the admin host', function () {
    $tenant = $this->createTenant('acme');
    $tenant->domains()->create(['domain' => 'admin', 'type' => Domain::TYPE_SUBDOMAIN]);

    $this->get('http://admin.localhost/login')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Platform/Pages/Auth/Login')
            ->where('app.area', 'platform')
            ->missing('tenant'));

    expect(app(TenantContext::class)->check())->toBeFalse();
});

it('does not expose workspace routes on the admin or central host', function (string $method, string $path) {
    $this->createTenant('acme');

    $this->call($method, 'http://admin.localhost'.$path)->assertNotFound();
    $this->call($method, 'http://localhost'.$path)->assertNotFound();
})->with([
    ['GET', '/team/members'],
    ['GET', '/team/roles'],
    ['GET', '/contacts'],
    ['GET', '/organization'],
    ['GET', '/billing'],
    ['GET', '/settings/profile'],
    ['GET', '/settings/workspace'],
    ['GET', '/forgot-password'],
    ['GET', '/auth/handoff'],
    ['POST', '/team/invitations'],
    ['GET', '/invitations/'.str_repeat('a', 64)],
]);

it('does not expose the workspace sign-in on the central host', function () {
    $this->createTenant('acme');

    $this->get('http://localhost/login')->assertNotFound();
    $this->post('http://localhost/login', ['email' => 'a@b.test', 'password' => 'x'])->assertNotFound();
});

it('does not expose admin routes on a workspace or the central host', function (string $path) {
    $tenant = $this->createTenant('acme');

    $this->actingAsOwner($tenant)->get('http://acme.localhost'.$path)->assertNotFound();
    $this->get('http://localhost'.$path)->assertNotFound();
})->with(['/tenants', '/two-factor/setup', '/horizon']);

it('blocks a suspended or pending workspace with a 403 page', function (TenantStatus $status) {
    $tenant = $this->createTenant('acme', ['name' => 'Acme Trading', 'status' => $status]);
    $owner = $this->ownerOf($tenant);

    $this->get('http://acme.localhost/login')
        ->assertForbidden()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Core/Pages/Errors/WorkspaceUnavailable')
            ->where('workspace', 'Acme Trading')
            ->where('status', $status->value));

    // Nobody can sign in ...
    $this->post('http://acme.localhost/login', ['email' => $owner->email, 'password' => 'password'])
        ->assertForbidden();

    $this->assertGuest('web');

    // ... and an existing session stops working too.
    $this->actingAsOwner($tenant)->get('http://acme.localhost/')->assertForbidden();
    $this->actingAsOwner($tenant)->get('http://acme.localhost/team/members')->assertForbidden();
})->with([
    'suspended' => [TenantStatus::Suspended],
    'pending' => [TenantStatus::Pending],
]);

it('serves each request in the context of its own host', function () {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $context = fn (): ?string => app(TenantContext::class)->id();

    $this->get('http://acme.localhost/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'acme'));
    expect($context())->toBe($acme->id);

    // A different workspace straight after: nothing of acme survives.
    $this->get('http://globex.localhost/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'globex'));
    expect($context())->toBe($globex->id);

    // The central site and the admin panel both drop the tenant a previous request left active.
    $this->get('http://localhost/register')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('app.area', 'central')->missing('tenant'));
    expect($context())->toBeNull();

    $this->get('http://acme.localhost/login')->assertOk();
    expect($context())->toBe($acme->id);

    $this->get('http://admin.localhost/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('app.area', 'platform')->missing('tenant'));
    expect($context())->toBeNull();

    // And an unknown workspace is a 404 even right after a real one.
    $this->get('http://globex.localhost/login')->assertOk();
    $this->get('http://nobody.localhost/login')->assertNotFound();
    $this->get('http://acme.localhost/login')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tenant.slug', 'acme'));
});
