<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\CampaignStatus;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A template sent to a list of contacts. Sending happens in bounded chunks; consent is decided by the communication platform per recipient.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_id
 * @property string|null $department_id
 * @property string|null $branch_id
 * @property string $name
 * @property CampaignStatus $status
 * @property string|null $template_id
 * @property string|null $list_id
 * @property string $template_name
 * @property string $template_language
 * @property string $template_category
 * @property string $template_body
 * @property string|null $list_name
 * @property array<int, array<string, string>> $variable_map
 * @property string|null $header_media_type
 * @property string|null $header_media_id
 * @property list<array{sub_type: string, index: int, value: string}>|null $button_params
 * @property string|null $sender_number_id
 * @property CarbonInterface|null $scheduled_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $snapshot_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $cancelled_at
 * @property int $total_recipients
 * @property string|null $stop_reason
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, Recipient> $recipients
 * @property-read Template|null $template
 * @property-read ContactList|null $list
 */
class Campaign extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'whatsapp_campaigns';

    /** @var list<string> */
    protected $fillable = ['name', 'status', 'template_id', 'list_id', 'template_name', 'template_language', 'template_category', 'template_body', 'list_name', 'variable_map', 'header_media_type', 'header_media_id', 'button_params', 'sender_number_id', 'scheduled_at', 'started_at', 'snapshot_at', 'completed_at', 'cancelled_at', 'total_recipients', 'stop_reason', 'created_by', 'owner_id', 'department_id', 'branch_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'variable_map' => 'array',
            'button_params' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'snapshot_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'total_recipients' => 'integer',
        ];
    }

    /** @return HasMany<Recipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(Recipient::class, 'campaign_id');
    }

    /** @return BelongsTo<Template, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    /** @return BelongsTo<ContactList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(ContactList::class, 'list_id');
    }
}
