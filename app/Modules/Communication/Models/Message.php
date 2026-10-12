<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Company;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\MessagePurpose;
use App\Modules\Communication\Enums\MessageStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the outbox and message log.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $company_id
 * @property string|null $membership_id
 * @property string|null $template_id
 * @property Channel $channel
 * @property MessagePurpose $purpose
 * @property MessageStatus $status
 * @property string $recipient
 * @property string|null $subject
 * @property string $body
 * @property string $locale
 * @property string $idempotency_key
 * @property string|null $source
 * @property string|null $provider
 * @property string|null $provider_message_id
 * @property int $attempts
 * @property Carbon|null $queued_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $sending_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $meta
 * @property Carbon $created_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 */
class Message extends Model
{
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'communication_messages';

    /** @var list<string> */
    protected $fillable = [
        'contact_id', 'company_id', 'membership_id', 'template_id', 'channel', 'purpose', 'status', 'recipient',
        'subject', 'body', 'locale', 'idempotency_key', 'source', 'provider', 'provider_message_id', 'attempts',
        'queued_at', 'next_attempt_at', 'dispatched_at', 'sending_at', 'sent_at', 'delivered_at', 'failed_at',
        'failure_code', 'failure_message', 'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'purpose' => MessagePurpose::class,
            'status' => MessageStatus::class,
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'sending_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
