<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A provider event id already processed. No payload is kept.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $provider
 * @property string $event_id
 * @property string $type
 * @property string|null $provider_message_id
 * @property Carbon $received_at
 */
class WebhookEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_webhook_events';

    /** @var list<string> */
    protected $fillable = ['provider', 'event_id', 'type', 'provider_message_id', 'received_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}
