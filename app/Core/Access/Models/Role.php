<?php

declare(strict_types=1);

namespace App\Core\Access\Models;

use App\Core\Access\PermissionResolver;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Models\Tenant;
use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * A tenant's role: a named set of permission grants.
 *
 * Roles are tenant-owned, but this model does not carry the automatic tenant
 * scope: the permission package loads roles through its own queries and a
 * global scope there would leak one tenant's view into its shared cache.
 * Tenant isolation is instead explicit and enforced in three places:
 *
 *  - {@see scopeForCurrentTenant()} for every listing;
 *  - {@see resolveRouteBinding()} for every `{role}` route parameter;
 *  - the `tenant_id` filters in {@see PermissionResolver}.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $guard_name
 * @property string|null $description
 * @property bool $is_system
 */
class Role extends SpatieRole
{
    use Auditable;
    use HasUlids;

    public const string OWNER = 'Owner';

    public const string ADMIN = 'Admin';

    public const string MANAGER = 'Manager';

    public const string EMPLOYEE = 'Employee';

    /**
     * The system roles, from the most powerful down: the order to list them in.
     *
     * @var list<string>
     */
    public const array SYSTEM_ORDER = [self::OWNER, self::ADMIN, self::MANAGER, self::EMPLOYEE];

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Grants with the scope of each one.
     *
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return parent::permissions()->withPivot('scope');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeForCurrentTenant(Builder $query): void
    {
        $query->where($this->qualifyColumn('tenant_id'), app(TenantContext::class)->requireId());
    }

    /**
     * Route-model binding never resolves another tenant's role.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        return $this->newQuery()
            ->forCurrentTenant()
            ->where($field ?? $this->getRouteKeyName(), $value)
            ->first();
    }

    public function isOwnerRole(): bool
    {
        return $this->is_system && $this->name === self::OWNER;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
