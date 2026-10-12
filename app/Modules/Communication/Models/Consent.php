<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Contacts\Models\Contact;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\ConsentStatus;
use App\Modules\Communication\Enums\MessagePurpose;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Current consent of a contact for a purpose on a channel. History is in the audit trail.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $contact_id
 * @property string|null $recorded_by
 * @property Channel $channel
 * @property MessagePurpose $purpose
 * @property ConsentStatus $status
 * @property string $method
 * @property string|null $evidence
 * @property Carbon $recorded_at
 * @property-read Contact|null $contact
 */
class Consent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_consents';

    /** @var list<string> */
    protected $fillable = ['contact_id', 'recorded_by', 'channel', 'purpose', 'status', 'method', 'evidence', 'recorded_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'purpose' => MessagePurpose::class,
            'status' => ConsentStatus::class,
            'recorded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
