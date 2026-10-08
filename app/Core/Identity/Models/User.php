<?php

declare(strict_types=1);

namespace App\Core\Identity\Models;

use App\Core\Identity\Concerns\HasTwoFactorAuthentication;
use App\Core\Identity\Contracts\TwoFactorAuthenticatable;
use App\Core\Identity\Enums\MembershipStatus;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * A person who signs in to workspaces.
 *
 * A user is a platform-wide identity, not tenant-owned data: the same person
 * can be a member of several tenants. Never expose or route-bind a User
 * directly inside a workspace - go through {@see TenantMembership}, which is
 * tenant-scoped.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $locale
 * @property array<string, mixed>|null $preferences
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements TwoFactorAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasTwoFactorAuthentication;
    use HasUlids;
    use Notifiable;
    use UsesCentralConnection;

    /** Guard the permission package resolves roles against. */
    protected string $guard_name = 'web';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'preferences',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'preferences' => 'array',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * Every workspace this person belongs to, across tenants. This is the one
     * legitimate cross-tenant read of memberships: it is keyed by the user.
     *
     * @return HasMany<TenantMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<TenantMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->where('status', MembershipStatus::Active);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $letters = array_map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)), $parts);

        return implode('', array_slice($letters, 0, 2)) ?: '?';
    }
}
