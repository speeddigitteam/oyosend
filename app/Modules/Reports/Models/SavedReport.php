<?php

declare(strict_types=1);

namespace App\Modules\Reports\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Reports\Enums\RangeMode;
use App\Modules\Reports\Enums\Visibility;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named, declarative report setup: which provider, which columns, which filters and which
 * dates. It holds no data and no query; opening it re-validates it against the provider's
 * current definition and runs it as the person who opened it.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $owner_membership_id
 * @property string $name
 * @property string $provider_key
 * @property list<string>|null $columns
 * @property array<string, string|int|bool>|null $filters
 * @property RangeMode $range_mode
 * @property int $range_days
 * @property Carbon|null $range_from
 * @property Carbon|null $range_to
 * @property Visibility $visibility
 * @property string|null $role_id
 * @property string|null $department_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TenantMembership|null $owner
 */
class SavedReport extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'reports_saved_reports';

    /** @var list<string> */
    protected $fillable = [
        'owner_membership_id', 'name', 'provider_key', 'columns', 'filters', 'range_mode', 'range_days',
        'range_from', 'range_to', 'visibility', 'role_id', 'department_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'filters' => 'array',
            'range_mode' => RangeMode::class,
            'range_days' => 'integer',
            'range_from' => 'date',
            'range_to' => 'date',
            'visibility' => Visibility::class,
        ];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_membership_id');
    }

    public function auditEventName(string $verb): string
    {
        return 'reports.saved.'.$verb;
    }
}
