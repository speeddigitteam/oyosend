<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\ChatDirection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a website chat. Plain text, stored as written (control characters removed) and shown as text
 * everywhere. `seq` is the message's position in its conversation; polling continues after it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property int $seq
 * @property ChatDirection $direction
 * @property string $text
 * @property string|null $sent_by_membership_id
 * @property Carbon $created_at
 * @property-read ChatConversation $conversation
 * @property-read TenantMembership|null $sentBy
 */
class ChatMessage extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_chat_messages';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'seq', 'direction', 'text', 'sent_by_membership_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'direction' => ChatDirection::class,
        ];
    }

    /** @return BelongsTo<ChatConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'sent_by_membership_id');
    }
}
