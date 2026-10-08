<?php

declare(strict_types=1);

namespace App\Core\Identity\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Enums\MembershipStatus;
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
 * @property Carbon|null $joined_at
 * @property Carbon|null $last_seen_at
 * @property-read User $user
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
