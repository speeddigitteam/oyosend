<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\Direction;
use App\Modules\WhatsappMarketing\Enums\MessageStatus;
use App\Modules\WhatsappMarketing\Enums\MessageType;
use App\Modules\WhatsappMarketing\Enums\SenderType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message of a conversation. Inbound bodies are untrusted customer text: render as text only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string|null $campaign_id
 * @property string|null $template_id
 * @property Direction $direction
 * @property SenderType $sender_type
 * @property string|null $author_id
 * @property MessageType $type
 * @property string|null $body
 * @property string|null $template_name
 * @property MessageStatus $status
 * @property string|null $failure_code
 * @property string|null $provider_message_id
 * @property string|null $communication_message_id
 * @property list<array<string, scalar|null>>|null $attachments
 * @property bool|null $billable
 * @property string|null $pricing_category
 * @property CarbonInterface $occurred_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $read_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation|null $conversation
 * @property-read TenantMembership|null $author
 * @property-read Campaign|null $campaign
 */
class Message extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_messages';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'campaign_id', 'template_id', 'direction', 'sender_type', 'author_id', 'type', 'body', 'template_name', 'status', 'failure_code', 'provider_message_id', 'communication_message_id', 'attachments', 'billable', 'pricing_category', 'occurred_at', 'delivered_at', 'read_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => Direction::class,
            'sender_type' => SenderType::class,
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'attachments' => 'array',
            'billable' => 'boolean',
            'occurred_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

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

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
