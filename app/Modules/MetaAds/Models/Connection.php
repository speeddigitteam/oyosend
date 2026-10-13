<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\MetaAds\Enums\ConnectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A workspace's Facebook Login authorisation for the Marketing API: the person who authorised and the
 * long-lived user access token acting for them.
 *
 * The token is encrypted at rest by the `encrypted` cast and hidden: it is not in `toArray()`, JSON, an
 * audit entry or any Inertia prop. Only the connection manager and the Marketing API client read it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $external_user_id
 * @property string|null $user_name
 * @property string|null $access_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $token_renewed_at
 * @property list<string>|null $granted_scopes
 * @property list<string>|null $declined_scopes
 * @property ConnectionStatus $status
 * @property string|null $status_reason
 * @property string|null $connected_by
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_checked_at
 * @property string|null $last_error
 */
class Connection extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'meta_ads_connections';

    /** @var list<string> */
    protected $fillable = [
        'external_user_id', 'user_name', 'access_token', 'token_expires_at', 'token_renewed_at', 'granted_scopes',
        'declined_scopes', 'status', 'status_reason', 'connected_by', 'connected_at', 'last_checked_at', 'last_error',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConnectionStatus::class,
            'access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'token_renewed_at' => 'datetime',
            'granted_scopes' => 'array',
            'declined_scopes' => 'array',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return HasMany<AdAccount, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(AdAccount::class, 'connection_id');
    }

    public function isUsable(): bool
    {
        return $this->status === ConnectionStatus::Connected && $this->access_token !== null;
    }

    public function canManage(): bool
    {
        return $this->isUsable() && in_array('ads_management', $this->granted_scopes ?? [], true);
    }
}
