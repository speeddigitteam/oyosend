<?php

declare(strict_types=1);

namespace App\Core\Identity\Models;

use App\Core\Access\Models\Role;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An emailed offer to join a workspace with a given role.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $email
 * @property string $role_id
 * @property string|null $invited_by
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read Role $role
 * @property-read User|null $inviter
 */
#[UseFactory(InvitationFactory::class)]
class Invitation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use HasUlids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'email',
        'role_id',
        'invited_by',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
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
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', Carbon::now());
    }

    /**
     * Not yet accepted or revoked - may still have expired.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function hasExpired(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isPast();
    }
}
