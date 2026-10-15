<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Telephony\Enums\CallDirection;
use App\Modules\Telephony\Enums\CallMode;
use App\Modules\Telephony\Enums\CallStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call in the log. It belongs to the member who made or took it ({@see HasRecordOwner}), so reading it honours
 * the scope of `telephony.calls.view`. `confirmed_at` is set once the PBX itself reported the call; until then the
 * row holds what the member's browser said, which is shown but is never the record of consequence. No recording.
 *
 * @property string $id
 * @property CallDirection $direction
 * @property CallMode $mode
 * @property CallStatus $status
 * @property string $remote_number
 * @property string|null $extension
 * @property string|null $contact_id
 * @property string|null $client_call_id
 * @property string|null $pbx_call_id
 * @property Carbon $started_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $ended_at
 * @property int|null $duration_seconds
 * @property Carbon|null $confirmed_at
 * @property string|null $owner_id
 * @property Carbon $created_at
 * @property-read Contact|null $contact
 */
class Call extends Model
{
    use BelongsToTenant, HasRecordOwner, HasUlids;

    protected $table = 'telephony_calls';

    protected $fillable = [
        'direction', 'mode', 'status', 'remote_number', 'extension', 'contact_id', 'client_call_id', 'pbx_call_id',
        'started_at', 'answered_at', 'ended_at', 'duration_seconds', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => CallDirection::class,
            'mode' => CallMode::class,
            'status' => CallStatus::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function auditLabel(): string
    {
        return $this->remote_number;
    }
}
