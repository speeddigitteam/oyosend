<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Core\Identity\Concerns\HasTwoFactorAuthentication;
use App\Core\Identity\Contracts\TwoFactorAuthenticatable;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use App\Platform\Enums\PlatformRole;
use Database\Factories\PlatformUserFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Someone who operates the platform. Lives in its own table behind its own
 * guard and can never authenticate into a tenant workspace.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property PlatformRole $role
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property Carbon|null $last_login_at
 */
#[UseFactory(PlatformUserFactory::class)]
class PlatformUser extends Authenticatable implements TwoFactorAuthenticatable
{
    /** @use HasFactory<PlatformUserFactory> */
    use HasFactory;

    use HasTwoFactorAuthentication;
    use HasUlids;
    use Notifiable;
    use UsesCentralConnection;

    protected $table = 'platform_users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
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
            'password' => 'hashed',
            'role' => PlatformRole::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === PlatformRole::SuperAdmin;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name)) ?: [];
        $letters = array_map(static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)), $parts);

        return implode('', array_slice($letters, 0, 2)) ?: '?';
    }
}
