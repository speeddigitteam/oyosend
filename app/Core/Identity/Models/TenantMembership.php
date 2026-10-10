<?php

declare(strict_types=1);

namespace App\Core\Identity\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Organization\Models\Branch;
use App\Core\Organization\Models\Department;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Database\Factories\TenantMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links a user to a tenant. This is the tenant-scoped handle for "a person in
 * this workspace" and the model workspace routes bind instead of User.
 *
 * It stays on the central database even for tenants with a dedicated one:
 * sign-in has to find a user's workspaces before any tenant is known.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property MembershipStatus $status
 * @property bool $is_owner
 * @property string|null $branch_id
 * @property string|null $department_id
 * @property Carbon|null $joined_at
 * @property Carbon|null $last_seen_at
 * @property-read User $user
 * @property-read Branch|null $branch
 * @property-read Department|null $department
 */
#[UseFactory(TenantMembershipFactory::class)]
class TenantMembership extends Model
{
    use Auditable;
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }

    /** @use HasFactory<TenantMembershipFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'user_id',
        'status',
        'is_owner',
        'branch_id',
        'department_id',
        'joined_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    public function auditLabel(): string
    {
        return $this->relationLoaded('user') ? $this->user->email : $this->user()->value('email') ?? $this->id;
    }

    /**
     * @return list<string>
     */
    protected function auditExcluded(): array
    {
        return ['last_seen_at'];
    }
}
