<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Telephony\Enums\PbxDriver;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How the workspace reaches its PBX (ADR 0036). One row per workspace. The API secret, the TURN credential and the
 * webhook secret are encrypted at rest and never leave the server except the TURN credential, which a member's own
 * softphone needs; none of them is ever shown again once saved.
 *
 * @property string $id
 * @property string $tenant_id
 * @property PbxDriver $driver
 * @property string|null $wss_url
 * @property string|null $sip_domain
 * @property string|null $api_url
 * @property string|null $api_username
 * @property string|null $api_secret
 * @property string|null $origination_context
 * @property string|null $caller_id
 * @property list<string>|null $stun_urls
 * @property string|null $turn_url
 * @property string|null $turn_username
 * @property string|null $turn_credential
 * @property string|null $webhook_secret
 */
class TelephonySetting extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'telephony_settings';

    protected $fillable = [
        'driver', 'wss_url', 'sip_domain', 'api_url', 'api_username', 'api_secret', 'origination_context', 'caller_id',
        'stun_urls', 'turn_url', 'turn_username', 'turn_credential', 'webhook_secret',
    ];

    protected $hidden = ['api_secret', 'turn_credential', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'driver' => PbxDriver::class,
            'api_secret' => 'encrypted',
            'turn_credential' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'stun_urls' => 'array',
        ];
    }

    /** The softphone can register: a secure WebSocket and a SIP domain are known. */
    public function softphoneReady(): bool
    {
        return $this->wss_url !== null && $this->sip_domain !== null;
    }

    /** Bridge calls can be originated: the PBX control API is known. */
    public function bridgeReady(): bool
    {
        return $this->driver === PbxDriver::Fake
            || ($this->api_url !== null && $this->api_username !== null && $this->api_secret !== null);
    }

    public function auditLabel(): string
    {
        return 'Telephony settings';
    }
}
