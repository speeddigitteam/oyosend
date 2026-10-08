<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Access\Enums\AccessScope;
use App\Core\Access\Models\Role;
use App\Core\Identity\Models\User;
use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Reads what a user has been granted in the active tenant.
 *
 * One indexed query per (tenant, user), cached in the tenant's own cache
 * namespace. It never loads the platform-wide role/permission map, so the
 * cost stays flat as the number of tenants grows.
 */
final class PermissionResolver
{
    private const int CACHE_TTL = 600;

    /** @var array<string, array<string, AccessScope>> per-request memo */
    private array $resolved = [];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array<string, AccessScope> permission name => widest granted scope
     */
    public function grantsFor(User $user): array
    {
        $tenantId = $this->context->requireId();
        $memoKey = $tenantId.':'.$user->getKey();

        if (isset($this->resolved[$memoKey])) {
            return $this->resolved[$memoKey];
        }

        /** @var array<string, string> $raw */
        $raw = Cache::remember(
            $this->cacheKey($user),
            self::CACHE_TTL,
            fn (): array => $this->query($user, $tenantId),
        );

        return $this->resolved[$memoKey] = array_map(
            static fn (string $scope): AccessScope => AccessScope::tryFrom($scope) ?? AccessScope::Own,
            $raw,
        );
    }

    /**
     * Forget every cached grant of the active tenant. Call it after any
     * change to roles, their permissions, or who holds them.
     */
    public function flush(): void
    {
        $this->context->requireId();
        $this->resolved = [];

        Cache::forever('access:version', $this->version() + 1);
    }

    /**
     * @return array<string, string> permission name => scope
     */
    private function query(User $user, string $tenantId): array
    {
        $rows = (new Role)->getConnection()
            ->table('model_has_roles as assignment')
            ->join('roles', 'roles.id', '=', 'assignment.role_id')
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'roles.id')
            ->join('permissions', 'permissions.id', '=', 'rp.permission_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.model_type', $user->getMorphClass())
            ->where('assignment.model_id', $user->getKey())
            // Belt and braces: the role itself must belong to the same tenant.
            ->where('roles.tenant_id', $tenantId)
            ->where('permissions.guard_name', 'web')
            ->get(['permissions.name', 'rp.scope']);

        $grants = [];

        foreach ($rows as $row) {
            $scope = AccessScope::tryFrom((string) $row->scope) ?? AccessScope::Own;
            $current = isset($grants[$row->name]) ? AccessScope::from($grants[$row->name]) : null;

            $grants[$row->name] = ($current?->widest($scope) ?? $scope)->value;
        }

        return $grants;
    }

    private function cacheKey(User $user): string
    {
        return 'access:grants:'.$this->version().':'.$user->getKey();
    }

    private function version(): int
    {
        return (int) Cache::get('access:version', 1);
    }
}
