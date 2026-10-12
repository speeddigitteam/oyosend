<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\TicketEventKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only activity history of a ticket.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_id
 * @property string|null $actor_id
 * @property TicketEventKind $kind
 * @property array<string, mixed>|null $data
 * @property Carbon $created_at
 * @property-read TenantMembership|null $actor
 */
class TicketEvent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'support_ticket_events';

    /** @var list<string> */
    protected $fillable = ['ticket_id', 'actor_id', 'kind', 'data'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Ticket history is append-only.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => TicketEventKind::class, 'data' => 'array', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'actor_id');
    }
}
