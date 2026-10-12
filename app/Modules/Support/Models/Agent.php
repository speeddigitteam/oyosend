<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace member enrolled to work tickets. Counts against the plan's `support.agents` limit while active.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property bool $is_active
 * @property bool $is_available
 * @property int|null $max_open_tickets
 * @property-read TenantMembership $membership
 */
class Agent extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_agents';

    /** @var list<string> */
    protected $fillable = ['membership_id', 'is_active', 'is_available', 'max_open_tickets'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_available' => 'boolean', 'max_open_tickets' => 'integer'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
