<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An internal note on a conversation: visible to the team that can see the conversation, never sent to the
 * customer. The body is plain text, rendered as text only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string|null $author_id
 * @property string $body
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation|null $conversation
 * @property-read TenantMembership|null $author
 */
class ConversationNote extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_conversation_notes';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'author_id', 'body'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_id');
    }
}
