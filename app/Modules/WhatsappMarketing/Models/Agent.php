<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A workspace member enrolled to work the WhatsApp inbox. Counts against `whatsapp_marketing.agents` while active.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property bool $is_active
 * @property bool $is_available
 * @property int|null $max_open_conversations
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TenantMembership|null $membership
 */
class Agent extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_agents';

    /** @var list<string> */
    protected $fillable = ['membership_id', 'is_active', 'is_available', 'max_open_conversations'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'max_open_conversations' => 'integer',
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
