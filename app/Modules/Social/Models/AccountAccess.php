<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of an account's allow-list: a member or a role, and what it may do with the account.
 * An account with no entries is open to everyone who holds the matching permission.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $subject_type
 * @property string $subject_id
 * @property bool $can_publish
 * @property bool $can_approve
 * @property bool $can_analytics
 */
class AccountAccess extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string MEMBERSHIP = 'membership';

    public const string ROLE = 'role';

    protected $table = 'social_account_access';

    /** @var list<string> */
    protected $fillable = ['account_id', 'subject_type', 'subject_id', 'can_publish', 'can_approve', 'can_analytics'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['can_publish' => 'boolean', 'can_approve' => 'boolean', 'can_analytics' => 'boolean'];
    }
}
