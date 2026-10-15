<?php

declare(strict_types=1);

namespace App\Modules\Telephony\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A PBX event already handled; its key makes a redelivery a no-op.
 *
 * @property string $event_key
 * @property Carbon $received_at
 */
class WebhookEvent extends Model
{
    use BelongsToTenant, HasUlids;

    public $timestamps = false;

    protected $table = 'telephony_webhook_events';

    protected $fillable = ['event_key', 'received_at'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
