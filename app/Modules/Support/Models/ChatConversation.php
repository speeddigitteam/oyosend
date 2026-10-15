<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Actions\LiveChatConversion;
use App\Modules\Support\Enums\ChatStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A chat a visitor started from the workspace's website widget. The visitor is anonymous: `name` and `email` are
 * what they typed, and no shared contact is created from them automatically. A member may save the visitor as a
 * shared contact (`contact_id`) and turn the chat into a ticket (`ticket_id`), each by an explicit action
 * ({@see LiveChatConversion}). The visitor's token is known only by its SHA-256.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $visitor_token_hash
 * @property string|null $name
 * @property string|null $email
 * @property string|null $page_url
 * @property string|null $contact_id
 * @property string|null $ticket_id
 * @property ChatStatus $status
 * @property int $message_count
 * @property int $unread_count
 * @property string|null $assignee_membership_id
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_visitor_message_at
 * @property Carbon|null $closed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property string|null $preview Selected by list queries only: the newest message.
 * @property-read TenantMembership|null $assignee
 * @property-read Contact|null $contact
 * @property-read Ticket|null $ticket
 * @property-read Collection<int, ChatMessage> $messages
 */
class ChatConversation extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_chat_conversations';

    /** @var list<string> */
    protected $fillable = [
        'visitor_token_hash', 'name', 'email', 'page_url', 'contact_id', 'ticket_id', 'status', 'message_count', 'unread_count',
        'assignee_membership_id', 'last_message_at', 'last_visitor_message_at', 'closed_at',
    ];

    /** @var list<string> */
    protected $hidden = ['visitor_token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ChatStatus::class,
            'message_count' => 'integer',
            'unread_count' => 'integer',
            'last_message_at' => 'datetime',
            'last_visitor_message_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'assignee_membership_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function isClosed(): bool
    {
        return $this->status === ChatStatus::Closed;
    }

    /**
     * Adds the newest message's text as `preview` in the same query (no query per row).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public static function withPreview(Builder $query): Builder
    {
        return $query->select('support_chat_conversations.*')->addSelect(['preview' => ChatMessage::query()
            ->select('text')
            ->whereColumn('support_chat_messages.conversation_id', 'support_chat_conversations.id')
            ->orderByDesc('seq')
            ->limit(1)]);
    }

    /** What members see as the conversation's name. */
    public function displayName(): string
    {
        return $this->name ?? $this->email ?? __('Website visitor');
    }

    /** The audit trail names the chat, never the visitor's name or address. */
    public function auditLabel(): string
    {
        return 'Website chat';
    }
}
