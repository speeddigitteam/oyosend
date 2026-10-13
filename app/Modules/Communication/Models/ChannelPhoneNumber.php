<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Actions\ChannelNumbers;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One business number of a workspace's channel connection (ADR 0022). The connection holds the provider and the
 * credential reference; the number is just the provider's account id (`phone_number_id`), a label and flags.
 * Written only by {@see ChannelNumbers}, which also keeps the platform-wide claim.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $connection_id
 * @property string $channel
 * @property string $phone_number_id
 * @property string|null $display_number
 * @property string $label
 * @property bool $is_active
 * @property bool $is_default
 * @property int|null $default_key
 */
class ChannelPhoneNumber extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_channel_numbers';

    /** @var list<string> */
    protected $fillable = ['connection_id', 'channel', 'phone_number_id', 'display_number', 'label', 'is_active', 'is_default', 'default_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_default' => 'boolean', 'default_key' => 'integer'];
    }

    /** @return BelongsTo<ChannelConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ChannelConnection::class, 'connection_id');
    }
}
