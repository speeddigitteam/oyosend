<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The record that one inbound conversation produced a CRM lead through the `LeadIntake` contract. Ids only: the
 * shared contact id and the CRM lead id as opaque strings, never any CRM content, so this app imports no CRM
 * model. Unique per (tenant, message) so a message makes at most one capture.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property string $message_id
 * @property string|null $contact_id
 * @property string|null $lead_id
 * @property string $source
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation|null $conversation
 */
class CapturedLead extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_captured_leads';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'message_id', 'contact_id', 'lead_id', 'source', 'status'];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }
}
