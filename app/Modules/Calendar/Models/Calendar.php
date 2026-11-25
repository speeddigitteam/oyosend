<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Calendar\Access\CalendarAccess;
use App\Modules\Calendar\Enums\CalendarVisibility;
use App\Modules\Calendar\Enums\EventColor;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A calendar: a member's personal one (`owner_id` set) or a workspace calendar everyone sees (`owner_id` null).
 *
 * Personal calendars are private by person, not by record scope: who may see one is decided by its owner through
 * {@see CalendarVisibility}, never by a role's `all` scope ({@see CalendarAccess}).
 *
 * @property string $id
 * @property string|null $owner_id
 * @property string $name
 * @property string|null $description
 * @property EventColor $color
 * @property CalendarVisibility $visibility
 * @property bool $is_primary
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read TenantMembership|null $owner
 */
class Calendar extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'calendar_calendars';

    protected $fillable = ['name', 'description', 'color', 'visibility'];

    protected function casts(): array
    {
        return ['color' => EventColor::class, 'visibility' => CalendarVisibility::class, 'is_primary' => 'boolean'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'owner_id');
    }

    /** @return HasMany<CalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CalendarEvent::class, 'calendar_id');
    }

    public function isShared(): bool
    {
        return $this->owner_id === null;
    }

    public function auditLabel(): string
    {
        return str($this->name)->limit(80)->toString();
    }
}
