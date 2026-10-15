<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Models\SocialAccount;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One direct-message conversation between a connected Facebook Page (or its linked Instagram account) and one person.
 * The person is known only by the platform's page-scoped id; a name is stored only when the platform gave one.
 * `owner_id` is the member it is assigned to (the record scope of `social.messages.*`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $social_account_id
 * @property string $platform
 * @property string $participant_id
 * @property string|null $participant_name
 * @property string|null $last_message_preview
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property int $unread_count
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read SocialAccount $account
 */
class DirectConversation extends Model
{
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    public const string MESSENGER = 'messenger';

    public const string INSTAGRAM = 'instagram';

    /** @var list<string> */
    public const array PLATFORMS = [self::MESSENGER, self::INSTAGRAM];

    public const string OPEN = 'open';

    public const string CLOSED = 'closed';

    protected $table = 'social_dm_conversations';

    /** @var list<string> */
    protected $fillable = [
        'social_account_id', 'platform', 'participant_id', 'participant_name', 'last_message_preview', 'last_message_at',
        'last_inbound_at', 'unread_count', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'unread_count' => 'integer',
        ];
    }

    /**
     * The name to show: the platform's name when it gave one, otherwise the platform and the id's last four
     * characters. A name is never invented.
     */
    public function displayName(): string
    {
        if ($this->participant_name !== null && $this->participant_name !== '') {
            return $this->participant_name;
        }

        $tail = mb_substr($this->participant_id, -4);

        return $this->platform === self::INSTAGRAM
            ? __('Instagram user ••:tail', ['tail' => $tail])
            : __('Messenger user ••:tail', ['tail' => $tail]);
    }

    /** @return BelongsTo<SocialAccount, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** @return HasMany<DirectMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class, 'conversation_id');
    }
}
