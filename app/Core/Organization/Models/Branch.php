<?php

declare(strict_types=1);

namespace App\Core\Organization\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A location of the company: an office, a shop, a warehouse.
 *
 * Like memberships, the organisation chart stays on the central database:
 * authorization reads it on every request to evaluate `branch` and
 * `department` permission scopes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $code
 * @property string|null $phone
 * @property string|null $address
 */
#[UseFactory(BranchFactory::class)]
class Branch extends Model
{
    use Auditable;
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }

    /** @use HasFactory<BranchFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'code', 'phone', 'address'];

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }
}
