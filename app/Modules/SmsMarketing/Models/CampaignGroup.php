<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $campaign_id
 * @property string $group_id
 */
class CampaignGroup extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_campaign_groups';

    /** @var list<string> */
    protected $fillable = ['campaign_id', 'group_id'];

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id');
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }
}
