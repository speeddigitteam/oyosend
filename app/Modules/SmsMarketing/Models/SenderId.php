<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\SmsMarketing\Enums\RegistrationStatus;
use App\Modules\SmsMarketing\Enums\SenderIdType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A sender id the workspace records for its SMS: the text or number, its type and what the workspace says
 * about its registration. It is a record: the sending connection decides what recipients actually see.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $label
 * @property string $value
 * @property SenderIdType $type
 * @property RegistrationStatus $registration_status
 * @property string|null $registration_reference
 * @property string|null $registration_note
 */
class SenderId extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_sender_ids';

    /** @var list<string> */
    protected $fillable = ['label', 'value', 'type', 'registration_status', 'registration_reference', 'registration_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => SenderIdType::class, 'registration_status' => RegistrationStatus::class];
    }

    public function auditLabel(): string
    {
        return $this->label;
    }

    public function auditEventName(string $verb): string
    {
        return 'sms_marketing.sender_id.'.$verb;
    }
}
