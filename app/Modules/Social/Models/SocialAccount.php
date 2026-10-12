<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Actions\AccountManager;
use App\Modules\Social\Enums\AccountStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A connected social profile or page.
 *
 * The OAuth tokens are encrypted at rest by the `encrypted` cast and are hidden: they are not in
 * `toArray()`, JSON, the audit trail or any Inertia prop. Only {@see AccountManager}
 * and the publisher read them, to call the platform.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $platform
 * @property string $external_id
 * @property string $name
 * @property string|null $handle
 * @property string|null $avatar_url
 * @property string $account_type
 * @property AccountStatus $status
 * @property string|null $status_reason
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 * @property Carbon|null $refresh_token_expires_at
 * @property list<string>|null $scopes
 * @property string|null $connected_by
 * @property Carbon|null $connected_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $last_refreshed_at
 * @property string|null $last_error
 */
class SocialAccount extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_accounts';

    /** @var list<string> */
    protected $fillable = [
        'platform', 'external_id', 'name', 'handle', 'avatar_url', 'account_type', 'status', 'status_reason',
        'access_token', 'refresh_token', 'token_expires_at', 'refresh_token_expires_at', 'scopes', 'connected_by',
        'connected_at', 'last_used_at', 'last_refreshed_at', 'last_error',
    ];

    /** @var list<string> */
    protected $hidden = ['access_token', 'refresh_token'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
            'connected_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->status === AccountStatus::Connected;
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'social.account.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['access_token', 'refresh_token', 'token_expires_at', 'refresh_token_expires_at', 'last_used_at', 'last_refreshed_at'];
    }
}
