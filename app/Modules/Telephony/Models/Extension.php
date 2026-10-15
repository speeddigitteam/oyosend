<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's SIP extension on the workspace's PBX. The SIP password is encrypted at rest and handed only to that
 * member's own browser, which needs it to register the softphone (ADR 0036).
 *
 * @property string $id
 * @property string $membership_id
 * @property string $extension
 * @property string|null $sip_password
 * @property string|null $bridge_phone E.164; the member's own phone, rung first for a bridge call
 * @property-read TenantMembership $membership
 */
class Extension extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'telephony_extensions';

    protected $fillable = ['membership_id', 'extension', 'sip_password', 'bridge_phone'];

    protected $hidden = ['sip_password'];

    protected function casts(): array
    {
        return ['sip_password' => 'encrypted'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function membership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class);
    }

    public function auditLabel(): string
    {
        return 'Extension '.$this->extension;
    }
}
