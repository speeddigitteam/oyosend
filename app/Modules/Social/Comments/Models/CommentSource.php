<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per connected Page: whether its comments are ingested, the webhook subscription state and the poll bookkeeping.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property bool $is_enabled
 * @property string $webhook_state
 * @property Carbon|null $webhook_checked_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $last_polled_at
 * @property string|null $last_error_code
 * @property int $consecutive_failures
 * @property-read SocialAccount $account
 */
class CommentSource extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string NONE = 'none';

    public const string SUBSCRIBED = 'subscribed';

    public const string FAILED = 'failed';

    protected $table = 'social_comment_sources';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'is_enabled', 'webhook_state', 'webhook_checked_at', 'last_event_at', 'last_polled_at',
        'last_error_code', 'consecutive_failures',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'webhook_checked_at' => 'datetime',
            'last_event_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'account_id');
    }
}
