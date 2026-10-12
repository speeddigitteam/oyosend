<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * How a workspace reaches a channel. It holds no secret: `credential_ref` and `webhook_secret_ref`
 * only name entries of `config('communication.credentials')`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property Channel $channel
 * @property string $provider
 * @property bool $is_enabled
 * @property array<string, mixed>|null $settings
 * @property string|null $credential_ref
 * @property string|null $webhook_secret_ref
 * @property int|null $rate_limit_per_minute
 */
class ChannelConnection extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_channel_connections';

    /** @var list<string> */
    protected $fillable = ['channel', 'provider', 'is_enabled', 'settings', 'credential_ref', 'webhook_secret_ref', 'rate_limit_per_minute'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'is_enabled' => 'boolean',
            'settings' => 'array',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return $this->channel->value.' channel';
    }

    public function auditEventName(string $verb): string
    {
        return 'communication.channel.'.$verb;
    }
}
