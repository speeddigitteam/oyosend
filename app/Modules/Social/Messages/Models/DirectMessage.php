<?php

declare(strict_types=1);

namespace App\Modules\Social\Messages\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One direct message. Inbound text is untrusted (cleaned and capped on the way in, rendered only as text); an
 * attachment is a type, never a file. `provider_message_id` is the platform's `mid`, unique per workspace, so a
 * redelivered webhook stores nothing twice.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $direction
 * @property string|null $provider_message_id
 * @property string|null $text
 * @property string|null $attachment_type
 * @property string|null $sent_by_membership_id
 * @property string $status
 * @property Carbon $sent_at
 * @property Carbon $created_at
 * @property-read DirectConversation $conversation
 * @property-read TenantMembership|null $sender
 */
class DirectMessage extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const string IN = 'in';

    public const string OUT = 'out';

    public const string RECEIVED = 'received';

    public const string SENDING = 'sending';

    public const string SENT = 'sent';

    public const string DELIVERED = 'delivered';

    public const string READ = 'read';

    public const string DELETED = 'deleted';

    public const int TEXT_MAX = 4000;

    protected $table = 'social_dm_messages';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id', 'direction', 'provider_message_id', 'text', 'attachment_type', 'sent_by_membership_id',
        'status', 'sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    /** @return BelongsTo<DirectConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(DirectConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'sent_by_membership_id');
    }
}
