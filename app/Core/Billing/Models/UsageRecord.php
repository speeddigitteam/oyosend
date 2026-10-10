<?php

declare(strict_types=1);

namespace App\Core\Billing\Models;

use App\Core\Billing\UsageMeter;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Core\Tenancy\Concerns\UsesCentralConnection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One metered event: so many units of something, consumed once. Written only
 * through {@see UsageMeter}.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $meter
 * @property int $quantity
 * @property string $idempotency_key
 * @property array<string, mixed>|null $meta
 * @property Carbon $occurred_at
 */
class UsageRecord extends Model
{
    use BelongsToTenant, UsesCentralConnection {
        UsesCentralConnection::getConnectionName insteadof BelongsToTenant;
    }
    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['meter', 'quantity', 'idempotency_key', 'meta', 'occurred_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'meta' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
