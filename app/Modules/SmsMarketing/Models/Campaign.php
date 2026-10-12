<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\SmsMarketing\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A bulk or scheduled SMS to the contacts of one or more groups.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $template_id
 * @property string $body
 * @property string|null $sender_identity_id
 * @property CampaignStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $started_at
 * @property Carbon|null $materialized_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $hold_reason
 * @property int $estimated_recipients
 * @property int $estimated_segments
 * @property string|null $launched_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Campaign extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'sms_marketing_campaigns';

    /** @var list<string> */
    protected $fillable = [
        'name', 'template_id', 'body', 'sender_identity_id', 'status', 'scheduled_for', 'started_at', 'materialized_at',
        'completed_at', 'cancelled_at', 'hold_reason', 'estimated_recipients', 'estimated_segments', 'launched_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'materialized_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_recipients' => 'integer',
            'estimated_segments' => 'integer',
        ];
    }

    /** @return HasMany<CampaignGroup, $this> */
    public function groupLinks(): HasMany
    {
        return $this->hasMany(CampaignGroup::class, 'campaign_id');
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'campaign_id');
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    /** @return BelongsTo<SenderId, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(SenderId::class, 'sender_identity_id');
    }

    public function auditLabel(): string
    {
        // Never the text: the audit trail is read by people who may not see the campaign.
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'sms_marketing.campaign.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['body'];
    }
}
