<?php

declare(strict_types=1);

use App\Core\Access\Models\Role;
use App\Core\Audit\AuditLogger;
use App\Core\Tenancy\Enums\TenantStatus;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = $this->createPlatformUser();

    // Three workspaces of different sizes and states.
    $this->acme = $this->createTenant('acme', ['name' => 'Acme Trading']);
    $this->globex = $this->createTenant('globex', ['name' => 'Globex Bangladesh', 'locale' => 'bn']);
    $this->parked = $this->createTenant('parked', ['name' => 'Parked Ltd', 'status' => TenantStatus::Suspended]);

    $shared = $this->addMember($this->acme, Role::ADMIN);
    $this->addMember($this->acme, Role::EMPLOYEE);
    $this->addMember($this->globex, Role::EMPLOYEE, $shared);
});

describe('the dashboard', function () {
    it('renders in the central context with platform-wide figures', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Platform/Pages/Dashboard')
                ->where('app.area', 'platform')
                ->missing('tenant')
                ->where('auth.user.email', $this->admin->email)
                ->where('stats.tenants', 3)
                ->where('stats.active', 2)
                ->where('stats.suspended', 1)
                ->where('stats.pending', 0)
                ->where('stats.newThisMonth', 3)
                // 3 owners + 2 added people (one of them in two workspaces).
                ->where('stats.users', 5)
                ->where('stats.memberships', 6)
                ->where('stats.modules', 21)
                ->has('recentTenants', 3)
                ->has('health', 4));

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('counts the members of each workspace without a tenant context', function () {
        $members = [];

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertInertia(function (AssertableInertia $page) use (&$members): void {
                $members = collect($page->toArray()['props']['recentTenants'])->pluck('members', 'slug')->all();
            });

        expect($members)->toEqualCanonicalizing(['acme' => 3, 'globex' => 2, 'parked' => 1]);
    });

    it('shows platform-level activity only, never a workspace\'s audit trail', function () {
        $this->inTenant($this->acme, fn () => app(AuditLogger::class)->record('acme.private_event'));
        app(AuditLogger::class)->record('platform.maintenance_started');

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertInertia(function (AssertableInertia $page): void {
                $events = collect($page->toArray()['props']['activity'])->pluck('event')->all();

                expect($events)->toContain('platform.maintenance_started')
                    ->not->toContain('acme.private_event', 'tenant_membership.created', 'role.created');
            });
    });

    it('reports the health of the services it depends on', function () {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertInertia(function (AssertableInertia $page) use ($database): void {
                $health = collect($page->toArray()['props']['health'])->keyBy('key');

                expect($health->keys()->all())->toBe(['database', 'cache', 'queue', 'failed_jobs'])
                    ->and($health['database']['ok'])->toBeTrue()
                    ->and($health['database']['detail'])->toContain($database)
                    ->and($health['cache']['ok'])->toBeTrue()
                    ->and($health['queue']['ok'])->toBeTrue()
                    ->and($health['queue']['count'])->toBe(0)
                    ->and($health['failed_jobs'])->toMatchArray(['ok' => true, 'detail' => '', 'count' => 0]);
            });
    });

    it('reports failed jobs as a failing check with their number', function () {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'RuntimeException: boom',
            'failed_at' => now(),
        ]);

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $health = collect($page->toArray()['props']['health'])->keyBy('key');

                expect($health['failed_jobs'])->toBe(['key' => 'failed_jobs', 'ok' => false, 'detail' => '', 'count' => 1])
                    // The others are unaffected.
                    ->and($health['database']['ok'])->toBeTrue();
            });
    });

    it('still works right after a request that left a tenant active', function () {
        $this->actingAsOwner($this->acme)->get('http://acme.localhost/')->assertOk();
        expect(app(TenantContext::class)->id())->toBe($this->acme->id);

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats.memberships', 6)
                ->missing('tenant')
                ->missing('navigation')
                ->missing('workspaces'));

        expect(app(TenantContext::class)->check())->toBeFalse();
    });
});

describe('the tenant directory', function () {
    it('lists every workspace with its member count', function () {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('Platform/Pages/Tenants/Index')
                    ->where('tenants.total', 3)
                    ->where('filters', ['search' => '', 'status' => '']);

                $rows = collect($page->toArray()['props']['tenants']['data'])->keyBy('slug');

                expect($rows->keys()->sort()->values()->all())->toBe(['acme', 'globex', 'parked'])
                    ->and($rows['acme'])->toMatchArray([
                        'id' => $this->acme->id,
                        'name' => 'Acme Trading',
                        'url' => 'http://acme.localhost/',
                        'status' => 'active',
                        'locale' => 'en',
                        'currency' => 'BDT',
                        'timezone' => 'Asia/Dhaka',
                        'members' => 3,
                    ])
                    ->and($rows['globex'])->toMatchArray(['locale' => 'bn', 'members' => 2])
                    ->and($rows['parked'])->toMatchArray(['status' => 'suspended', 'members' => 1]);
            });

        expect(app(TenantContext::class)->check())->toBeFalse();
    });

    it('filters by name, address and status', function (string $query, array $expected) {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants?'.$query)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($expected): void {
                $slugs = collect($page->toArray()['props']['tenants']['data'])->pluck('slug')->sort()->values()->all();

                expect($slugs)->toBe($expected);
            });
    })->with([
        'by name' => ['search=Bangladesh', ['globex']],
        'by address' => ['search=acm', ['acme']],
        'by status' => ['status=suspended', ['parked']],
        'by both' => ['search=a&status=active', ['acme', 'globex']],
        'no match' => ['search=zzz', []],
    ]);

    it('rejects a filter value it does not know', function (string $query, string $field) {
        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants?'.$query)
            ->assertSessionHasErrors($field);
    })->with([
        ['status=archived', 'status'],
        ['search[]=a', 'search'],
        ['search='.str_repeat('a', 101), 'search'],
    ]);

    it('leaves deleted workspaces out', function () {
        $this->globex->delete();

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/tenants')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('tenants.total', 2));
    });
});

describe('the audit log', function () {
    it('pages past the first thirty entries and back', function () {
        foreach (range(1, 35) as $n) {
            app(AuditLogger::class)->record('platform.paging_check', properties: ['n' => $n]);
        }

        $next = null;

        $this->actingAsPlatformUser($this->admin)
            ->get('http://admin.localhost/audit-log?search=paging')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$next): void {
                $entries = $page->toArray()['props']['entries'];
                $next = $entries['next_page_url'];

                expect($entries['data'])->toHaveCount(30)
                    ->and($entries['per_page'])->toBe(30)
                    ->and($entries['data'][0]['properties'])->toBe(['n' => 35])
                    ->and($entries['prev_page_url'])->toBeNull()
                    // The link keeps the active search.
                    ->and($next)->toStartWith('http://admin.localhost/audit-log?search=paging&cursor=');
            });

        $previous = null;

        $this->get($next)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$previous): void {
                $entries = $page->toArray()['props']['entries'];
                $previous = $entries['prev_page_url'];

                expect($entries['data'])->toHaveCount(5)
                    ->and($entries['data'][4]['properties'])->toBe(['n' => 1])
                    ->and($entries['next_page_url'])->toBeNull()
                    ->and($previous)->toBeString();
            });

        $this->get($previous)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('entries.data', 30));
    });
});
