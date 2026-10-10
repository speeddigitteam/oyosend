<?php

declare(strict_types=1);

namespace App\Platform\Impersonation;

use App\Core\Identity\Models\User;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Core\Tenancy\Models\Tenant;
use App\Platform\Models\PlatformUser;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One support session: a Super Admin working inside a workspace as one of its
 * members, for a stated reason and a limited time. Platform data.
 *
 * @property string $id
 * @property string $platform_user_id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $reason
 * @property string|null $token_hash
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $started_at
 * @property Carbon $expires_at
 * @property Carbon|null $ended_at
 * @property string|null $ip_address
 * @property Carbon $created_at
 * @property-read PlatformUser $platformUser
 * @property-read User $user
 * @property-read Tenant $tenant
 */
class Impersonation extends Model
{
    use HasUlids;
    use UsesCentralConnection;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'platform_user_id', 'tenant_id', 'user_id', 'reason', 'token_hash', 'token_expires_at',
        'started_at', 'expires_at', 'ended_at', 'ip_address',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PlatformUser, $this>
     */
    public function platformUser(): BelongsTo
    {
        return $this->belongsTo(PlatformUser::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Started, not ended, and still inside its time limit.
     */
    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->ended_at === null && $this->expires_at->isFuture();
    }
}
