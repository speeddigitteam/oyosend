<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An in-app notification for one workspace member.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string $category
 * @property string $severity
 * @property string $title_key
 * @property string|null $body_key
 * @property array<string, scalar|null>|null $params
 * @property string|null $link_route
 * @property array<string, scalar>|null $link_params
 * @property string|null $source_module
 * @property string|null $dedupe_key
 * @property Carbon|null $read_at
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 */
class MemberNotification extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_notifications';

    /** @var list<string> */
    protected $fillable = [
        'membership_id', 'category', 'severity', 'title_key', 'body_key', 'params', 'link_route', 'link_params',
        'source_module', 'dedupe_key', 'read_at', 'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'params' => 'array',
            'link_params' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
