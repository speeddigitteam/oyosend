<?php

declare(strict_types=1);

namespace App\Core\Organization\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Database\Factories\DepartmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A team of the company: Sales, Accounts, Support. Not tied to a branch: a
 * person has one branch and one department, each optional.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string|null $description
 */
#[UseFactory(DepartmentFactory::class)]
class Department extends Model
{
    use Auditable;
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'code', 'description'];

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }
}
