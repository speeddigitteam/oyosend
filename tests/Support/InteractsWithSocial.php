<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Access\RoleManager;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\Models\Tenant;
use App\Modules\Social\Actions\PostManager;
use App\Modules\Social\Enums\AccountStatus;
use App\Modules\Social\Models\Post;
use App\Modules\Social\Models\SocialAccount;
use App\Modules\Social\Platforms\SocialPlatforms;
use Carbon\CarbonImmutable;
use Tests\Support\Fixtures\FakeSocialPlatform;
use Tests\TestCase;

/**
 * Building blocks for Social tests: a workspace entitled to the app, a scriptable fake platform
 * (registered in the platform registry for the test only), accounts, posts and role helpers.
 *
 * @mixin TestCase
 */
trait InteractsWithSocial
{
    protected FakeSocialPlatform $fake;

    protected function socialTenant(string $slug = 'social-ws'): Tenant
    {
        $tenant = $this->createTenant($slug, ['timezone' => 'Asia/Dhaka', 'currency' => 'BDT']);
        $this->entitle($tenant, 'social');

        return $tenant;
    }

    protected function fakePlatform(?FakeSocialPlatform $platform = null): FakeSocialPlatform
    {
        $this->fake = $platform ?? new FakeSocialPlatform;
        $registry = $this->app->make(SocialPlatforms::class);
        $registry->reset();
        $registry->extend($this->fake);

        return $this->fake;
    }

    protected function socialUrl(Tenant $tenant, string $path): string
    {
        return $this->tenantUrl($tenant, '/social'.$path);
    }

    /** @param array<string, mixed> $overrides */
    protected function socialAccount(Tenant $tenant, array $overrides = []): SocialAccount
    {
        return $this->inTenant($tenant, fn (): SocialAccount => SocialAccount::query()->create([
            'platform' => 'fake',
            'external_id' => 'ext-'.bin2hex(random_bytes(4)),
            'name' => 'Brand Page',
            'account_type' => 'page',
            'status' => AccountStatus::Connected,
            'access_token' => 'tok-access-'.bin2hex(random_bytes(4)),
            'refresh_token' => 'tok-refresh-'.bin2hex(random_bytes(4)),
            'token_expires_at' => CarbonImmutable::now()->addDays(10),
            ...$overrides,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $accountIds
     */
    protected function socialPost(Tenant $tenant, array $accountIds, array $attributes = [], ?TenantMembership $owner = null): Post
    {
        return $this->inTenant($tenant, fn (): Post => app(PostManager::class)->create(
            ['body' => 'Hello world', ...$attributes],
            array_map(static fn (string $id): array => ['account_id' => $id], $accountIds),
            [],
            $owner,
        ));
    }

    /**
     * A member with a custom role.
     *
     * @param  list<string>  $permissions
     * @param  array<string, string>  $scopes
     * @return array{0: User, 1: TenantMembership}
     */
    protected function socialMember(Tenant $tenant, array $permissions, array $scopes = []): array
    {
        $owner = $this->ownerOf($tenant);
        $role = $this->inTenant($tenant, fn () => app(RoleManager::class)->create(
            $owner,
            'Role '.bin2hex(random_bytes(4)),
            null,
            $permissions,
            $scopes,
        ));
        $user = $this->addMember($tenant, $role);

        return [$user, $this->membershipOf($tenant, $user)];
    }
}
