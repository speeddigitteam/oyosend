<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What the collector learned about one account: the permissions its token holds, the Meta tasks
 * of the person who authorised it, and the state of the last insights read. No token is stored here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property list<string>|null $granted_scopes
 * @property list<string>|null $tasks
 * @property string|null $tasks_status
 * @property Carbon|null $tasks_checked_at
 * @property string|null $insights_status
 * @property string|null $insights_error_code
 * @property Carbon|null $insights_synced_at
 * @property Carbon|null $insights_backfilled_at
 */
class AccountDiagnostic extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_account_diagnostics';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'granted_scopes', 'tasks', 'tasks_status', 'tasks_checked_at', 'insights_status',
        'insights_error_code', 'insights_synced_at', 'insights_backfilled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'granted_scopes' => 'array',
            'tasks' => 'array',
            'tasks_checked_at' => 'datetime',
            'insights_synced_at' => 'datetime',
            'insights_backfilled_at' => 'datetime',
        ];
    }
}
