<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\EmailMarketing\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One email sent to the members of a list (optionally narrowed by a segment) at one time.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $subject
 * @property string|null $preheader
 * @property list<array<string, mixed>> $blocks
 * @property string|null $template_id
 * @property string $list_id
 * @property string|null $segment_id
 * @property CampaignStatus $status
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $started_at
 * @property Carbon|null $prepared_at
 * @property Carbon|null $finished_at
 * @property string|null $paused_reason
 * @property bool $track_clicks
 * @property bool $ab_enabled
 * @property string|null $ab_subject_b
 * @property int $ab_test_percent
 * @property int $ab_wait_hours
 * @property string $ab_metric
 * @property string|null $ab_winner
 * @property string|null $ab_winner_reason
 * @property Carbon|null $ab_decide_at
 * @property Carbon|null $ab_decided_at
 * @property string|null $cursor
 * @property int $audience_total
 * @property Carbon $created_at
 * @property-read MailingList $list
 * @property-read Segment|null $segment
 */
class Campaign extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'email_marketing_campaigns';

    /** @var list<string> */
    protected $fillable = [
        'name', 'subject', 'preheader', 'blocks', 'template_id', 'list_id', 'segment_id', 'status', 'scheduled_for',
        'started_at', 'prepared_at', 'finished_at', 'paused_reason', 'track_clicks', 'ab_enabled', 'ab_subject_b',
        'ab_test_percent', 'ab_wait_hours', 'ab_metric', 'ab_winner', 'ab_winner_reason', 'ab_decide_at',
        'ab_decided_at', 'cursor', 'audience_total',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft', 'track_clicks' => true, 'ab_enabled' => false,
        'ab_test_percent' => 20, 'ab_wait_hours' => 4, 'ab_metric' => 'clicks', 'audience_total' => 0,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'blocks' => 'array',
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'prepared_at' => 'datetime',
            'finished_at' => 'datetime',
            'track_clicks' => 'boolean',
            'ab_enabled' => 'boolean',
            'ab_test_percent' => 'integer',
            'ab_wait_hours' => 'integer',
            'ab_decide_at' => 'datetime',
            'ab_decided_at' => 'datetime',
            'audience_total' => 'integer',
        ];
    }

    /** @return BelongsTo<MailingList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(MailingList::class, 'list_id');
    }

    /** @return BelongsTo<Segment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class, 'segment_id');
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class, 'campaign_id');
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.campaign.'.$verb;
    }

    /** @return list<string> */
    protected function auditExcluded(): array
    {
        return ['blocks', 'cursor'];
    }
}
