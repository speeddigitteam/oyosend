<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Membership of one list by one shared contact, with the per-app properties and the consent evidence the member
 * gave. Not audited row by row (imports create thousands); the managers write summary audit entries.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $list_id
 * @property string $contact_id
 * @property SubscriptionStatus $status
 * @property string $source
 * @property string|null $consent_method
 * @property string|null $consent_evidence
 * @property Carbon|null $consented_at
 * @property Carbon|null $subscribed_at
 * @property Carbon|null $unsubscribed_at
 * @property string|null $status_reason
 * @property array<string, string>|null $properties
 * @property string|null $added_by
 * @property-read MailingList $list
 * @property-read Contact $contact
 */
class Subscription extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_subscriptions';

    /** @var list<string> */
    protected $fillable = [
        'list_id', 'contact_id', 'status', 'source', 'consent_method', 'consent_evidence', 'consented_at',
        'subscribed_at', 'unsubscribed_at', 'status_reason', 'properties', 'added_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'properties' => 'array',
            'consented_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MailingList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
