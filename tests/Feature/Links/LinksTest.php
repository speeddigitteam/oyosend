<?php

declare(strict_types=1);

use App\Core\Access\RoleManager;
use App\Core\Automation\AutomationRegistry;
use App\Core\Reporting\Contracts\ReportProvider;
use App\Modules\Links\Enums\LinkKind;
use App\Modules\Links\Models\Link;
use App\Modules\Links\Reporting\LinksByKindReport;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->tenant = $this->createTenant('links-home');
    $this->entitle($this->tenant, 'links');
});

function linkAttributes(array $overrides = []): array
{
    return ['kind' => 'website', 'title' => 'Bank portal', 'address' => 'https://bank.example.com', ...$overrides];
}

it('keeps links isolated per tenant', function () {
    $this->assertTenantIsolated(Link::class, fn () => Link::query()->create(linkAttributes()));
});

it('hides another workspace\'s link behind a 404', function () {
    $link = $this->inTenant($this->tenant, fn (): Link => Link::query()->create(linkAttributes()));
    $intruder = $this->createTenant('links-intruder');
    $this->entitle($intruder, 'links');

    $this->assertNotFoundFromOtherTenant('PUT', "links/{$link->id}", $this->tenant, linkAttributes(['title' => 'Edited']), $intruder);
    $this->assertNotFoundFromOtherTenant('DELETE', "links/{$link->id}", $this->tenant, [], $intruder);
});

it('returns 404 for a workspace that is not entitled to links', function () {
    $other = $this->createTenant('links-off');

    $this->actingAsOwner($other)
        ->get($this->tenantUrl($other, '/links'))
        ->assertNotFound();
});

it('lets an owner save, pin, edit and delete a link', function () {
    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, '/links'), linkAttributes(['folder' => ' Banking ', 'is_pinned' => true, 'description' => 'Monthly statements']))
        ->assertRedirect();

    $link = $this->inTenant($this->tenant, fn (): Link => Link::query()->sole());
    expect($link->kind)->toBe(LinkKind::Website)
        ->and($link->folder)->toBe('Banking')
        ->and($link->is_pinned)->toBeTrue()
        ->and($link->owner_id)->not->toBeNull();

    $this->actingAsOwner($this->tenant)
        ->put($this->tenantUrl($this->tenant, "/links/{$link->id}"), linkAttributes(['kind' => 'email', 'title' => 'Support inbox', 'address' => 'support@example.com', 'folder' => '']))
        ->assertRedirect();

    $link->refresh();
    expect($link->kind)->toBe(LinkKind::Email)
        ->and($link->address)->toBe('support@example.com')
        ->and($link->folder)->toBeNull();

    $this->actingAsOwner($this->tenant)
        ->delete($this->tenantUrl($this->tenant, "/links/{$link->id}"))
        ->assertRedirect();

    expect($this->inTenant($this->tenant, fn (): int => Link::query()->count()))->toBe(0);
});

it('accepts only http(s) addresses for web links and real addresses for email links', function (string $kind, string $address) {
    $this->actingAsOwner($this->tenant)
        ->post($this->tenantUrl($this->tenant, '/links'), linkAttributes(['kind' => $kind, 'address' => $address]))
        ->assertSessionHasErrors('address');

    expect($this->inTenant($this->tenant, fn (): int => Link::query()->count()))->toBe(0);
})->with([
    'javascript url' => ['website', 'javascript:alert(1)'],
    'data url' => ['tool', 'data:text/html,<script>alert(1)</script>'],
    'ftp url' => ['document', 'ftp://files.example.com/report.pdf'],
    'no scheme' => ['website', 'example.com'],
    'email without at' => ['email', 'support.example.com'],
    'url in an email link' => ['email', 'https://example.com'],
]);

it('filters by kind, folder and search, and counts each kind', function () {
    $this->inTenant($this->tenant, function (): void {
        Link::query()->create(linkAttributes(['title' => 'Hosting panel', 'kind' => 'tool', 'address' => 'https://panel.example.com', 'folder' => 'Server']));
        Link::query()->create(linkAttributes(['title' => 'Company site', 'address' => 'https://www.example.com']));
        Link::query()->create(linkAttributes(['title' => 'Billing', 'kind' => 'email', 'address' => 'billing@example.com', 'folder' => 'Server']));
    });

    $this->actingAsOwner($this->tenant)
        ->get($this->tenantUrl($this->tenant, '/links?folder=Server&search=panel'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Modules/Links/Pages/Index')
            ->where('links.total', 1)
            ->where('links.data.0.title', 'Hosting panel')
            ->where('folders', ['Server'])
            ->where('kindCounts', ['email' => 1, 'tool' => 1, 'website' => 1]));

    $this->actingAsOwner($this->tenant)
        ->get($this->tenantUrl($this->tenant, '/links?kind=email'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('links.total', 1)->where('links.data.0.address', 'billing@example.com'));
});

it('shows a member with the own scope only their own links', function () {
    $owner = $this->ownerOf($this->tenant);
    $role = $this->inTenant($this->tenant, fn () => app(RoleManager::class)->create(
        $owner, 'Link keeper', null, ['links.links.view', 'links.links.manage'], ['links.links.view' => 'own', 'links.links.manage' => 'own'],
    ));
    $member = $this->addMember($this->tenant, $role);
    $this->inTenant($this->tenant, fn () => Link::query()->create(linkAttributes(['title' => 'Owner only'])));

    $this->actingAsMember($this->tenant, $member)
        ->post($this->tenantUrl($this->tenant, '/links'), linkAttributes(['title' => 'Mine']))
        ->assertRedirect();

    $this->actingAsMember($this->tenant, $member)
        ->get($this->tenantUrl($this->tenant, '/links'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('links.total', 1)->where('links.data.0.title', 'Mine')->where('owners', []));

    $other = $this->inTenant($this->tenant, fn (): Link => Link::query()->where('title', 'Owner only')->sole());

    $this->actingAsMember($this->tenant, $member)
        ->delete($this->tenantUrl($this->tenant, "/links/{$other->id}"))
        ->assertNotFound();
});

it('lets a viewer read links but not save them', function () {
    $role = $this->createRole($this->tenant, 'Link reader', ['links.links.view']);
    $viewer = $this->addMember($this->tenant, $role);

    $this->actingAsMember($this->tenant, $viewer)
        ->get($this->tenantUrl($this->tenant, '/links'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));

    $this->actingAsMember($this->tenant, $viewer)
        ->post($this->tenantUrl($this->tenant, '/links'), linkAttributes())
        ->assertForbidden();
});

it('registers its trigger and report provider', function () {
    expect(array_keys(app(AutomationRegistry::class)->registeredTriggers()))->toContain('links.link.created');

    $reports = collect(app()->tagged(ReportProvider::TAG))->map(fn (object $p): string => $p::class);
    expect($reports)->toContain(LinksByKindReport::class);
});
