<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\FeedbackStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rating request sent after a person closed a conversation, and the customer's answer (1 to 5).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string|null $contact_id
 * @property string|null $owner_id
 * @property string|null $message_id
 * @property FeedbackStatus $status
 * @property int|null $rating
 * @property CarbonInterface $requested_at
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $answered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation|null $conversation
 * @property-read TenantMembership|null $owner
 */
class Feedback extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_feedback';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'contact_id', 'owner_id', 'message_id', 'status', 'rating', 'requested_at', 'expires_at', 'answered_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FeedbackStatus::class,
            'rating' => 'integer',
            'requested_at' => 'datetime',
            'expires_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }
}
