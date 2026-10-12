<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\RecipientState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact of a campaign, with the state the communication outbox reported for its message.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string $contact_id
 * @property RecipientState $state
 * @property string|null $reason
 * @property string|null $message_id
 * @property string|null $communication_message_id
 * @property CarbonInterface|null $queued_at
 * @property CarbonInterface|null $sent_at
 * @property CarbonInterface|null $delivered_at
 * @property CarbonInterface|null $read_at
 * @property CarbonInterface|null $failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Contact|null $contact
 * @property-read Campaign|null $campaign
 */
class Recipient extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_campaign_recipients';

    /** @var list<string> */
    protected $fillable = ['campaign_id', 'contact_id', 'state', 'reason', 'message_id', 'communication_message_id', 'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => RecipientState::class,
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
