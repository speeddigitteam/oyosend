<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Messages\Actions\MessagingSetup;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per connected Facebook Page: whether its Messenger (and linked Instagram) direct messages are captured, what the
 * Page token holds and the Instagram professional account linked to the Page. Written only by {@see MessagingSetup}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $social_account_id
 * @property bool $is_enabled
 * @property string $state
 * @property string $instagram_state
 * @property string|null $instagram_account_id
 * @property list<string>|null $granted_scopes
 * @property list<string>|null $missing_scopes
 * @property string|null $last_error_code
 * @property string|null $last_error
 * @property Carbon|null $enabled_at
 * @property Carbon|null $last_event_at
 * @property-read SocialAccount $account
 */
class MessagingSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string OFF = 'off';

    public const string ON = 'on';

    public const string FAILED = 'failed';

    /** Instagram: the operator switched Instagram on, but the Page has no linked professional account. */
    public const string NOT_LINKED = 'not_linked';

    /** Instagram: the Page token lacks the Instagram messaging permissions. */
    public const string MISSING_PERMISSIONS = 'missing_permissions';

    /** Instagram: the linked account already feeds another workspace. */
    public const string TAKEN = 'taken';

    protected $table = 'social_dm_settings';

    /** @var list<string> */
    protected $fillable = [
        'social_account_id', 'is_enabled', 'state', 'instagram_state', 'instagram_account_id', 'granted_scopes',
        'missing_scopes', 'last_error_code', 'last_error', 'enabled_at', 'last_event_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'granted_scopes' => 'array',
            'missing_scopes' => 'array',
            'enabled_at' => 'datetime',
            'last_event_at' => 'datetime',
        ];
    }

    /** Whether Instagram messages of the linked account are captured. */
    public function capturesInstagram(): bool
    {
        return $this->is_enabled && $this->instagram_state === self::ON && $this->instagram_account_id !== null;
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
