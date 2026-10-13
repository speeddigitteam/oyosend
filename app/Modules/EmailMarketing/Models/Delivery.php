<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\DeliveryStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One email to one contact for a campaign or a sequence step. `send_key` is unique per workspace and doubles
 * as the idempotency key towards the platform gateway, so a retried or double-dispatched send is one email.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $campaign_id
 * @property string|null $sequence_id
 * @property string|null $step_id
 * @property string|null $enrollment_id
 * @property string $contact_id
 * @property string|null $subscription_id
 * @property string $send_key
 * @property string $variant
 * @property DeliveryStatus $status
 * @property string|null $reason
 * @property string|null $message_id
 * @property int $attempts
 * @property int $send_epoch
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $viewed_at
 * @property int $view_count
 * @property Carbon|null $clicked_at
 * @property int $click_count
 * @property-read Contact|null $contact
 */
class Delivery extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_deliveries';

    /** @var list<string> */
    protected $fillable = [
        'campaign_id', 'sequence_id', 'step_id', 'enrollment_id', 'contact_id', 'subscription_id', 'send_key', 'variant',
        'status', 'reason', 'message_id', 'attempts', 'send_epoch', 'sent_at', 'delivered_at', 'failed_at', 'viewed_at', 'view_count',
        'clicked_at', 'click_count',
    ];

    /** @var array<string, mixed> */
    protected $attributes = ['variant' => 'a', 'attempts' => 0, 'send_epoch' => 0, 'view_count' => 0, 'click_count' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DeliveryStatus::class,
            'attempts' => 'integer',
            'send_epoch' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'viewed_at' => 'datetime',
            'view_count' => 'integer',
            'clicked_at' => 'datetime',
            'click_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
