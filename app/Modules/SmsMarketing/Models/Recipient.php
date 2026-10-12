<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\SmsMarketing\Enums\RecipientStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One contact's place in one campaign and what became of the message.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string|null $contact_id
 * @property string|null $phone
 * @property RecipientStatus $status
 * @property string|null $reason
 * @property string|null $encoding
 * @property int $segments
 * @property int $attempts
 * @property string|null $message_id
 * @property Carbon|null $due_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 */
class Recipient extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_recipients';

    /** @var list<string> */
    protected $fillable = [
        'campaign_id', 'contact_id', 'phone', 'status', 'reason', 'encoding', 'segments', 'attempts', 'message_id',
        'due_at', 'next_attempt_at', 'queued_at', 'sent_at', 'delivered_at', 'failed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => RecipientStatus::class,
            'segments' => 'integer',
            'attempts' => 'integer',
            'due_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
