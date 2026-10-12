<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\ConversationStatus;
use App\Modules\WhatsappMarketing\Models\Concerns\HasAssignee;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A WhatsApp thread with one phone number. The owner is the assigned agent (an unassigned conversation has none). The customer-service window is `last_inbound_at` + 24 hours.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string|null $contact_id
 * @property string $phone
 * @property string|null $profile_name
 * @property ConversationStatus $status
 * @property list<string>|null $tags
 * @property CarbonInterface|null $last_inbound_at
 * @property CarbonInterface|null $last_message_at
 * @property string|null $last_message_preview
 * @property string|null $last_message_direction
 * @property int $unread_count
 * @property CarbonInterface|null $first_responded_at
 * @property CarbonInterface|null $closed_at
 * @property bool $bot_paused
 * @property array<string, mixed>|null $bot_state
 * @property CarbonInterface|null $bot_state_expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact|null $contact
 * @property-read Collection<int, Message> $messages
 */
class Conversation extends Model
{
    use BelongsToTenant;
    use HasAssignee;
    use HasUlids;

    protected $table = 'whatsapp_conversations';

    /** @var list<string> */
    protected $fillable = ['contact_id', 'phone', 'profile_name', 'status', 'tags', 'last_inbound_at', 'last_message_at', 'last_message_preview', 'last_message_direction', 'unread_count', 'first_responded_at', 'closed_at', 'bot_paused', 'bot_state', 'bot_state_expires_at', 'owner_id', 'department_id', 'branch_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'tags' => 'array',
            'last_inbound_at' => 'datetime',
            'last_message_at' => 'datetime',
            'unread_count' => 'integer',
            'first_responded_at' => 'datetime',
            'closed_at' => 'datetime',
            'bot_paused' => 'boolean',
            'bot_state' => 'array',
            'bot_state_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }
}
