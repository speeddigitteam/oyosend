<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Calendar\Enums\AttendeeResponse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace member invited to an event (always the series root), with their answer.
 *
 * @property string $id
 * @property string $event_id
 * @property string $membership_id
 * @property AttendeeResponse $response
 * @property CarbonImmutable|null $responded_at
 * @property-read CalendarEvent $event
 * @property-read TenantMembership $membership
 */
class Attendee extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'calendar_event_attendees';

    protected $fillable = ['event_id', 'membership_id', 'response', 'responded_at'];

    protected $attributes = ['response' => 'needs_action'];

    protected function casts(): array
    {
        return ['response' => AttendeeResponse::class, 'responded_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CalendarEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'membership_id');
    }
}
