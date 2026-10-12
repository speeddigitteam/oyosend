<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Talent\Enums\ApplicationEvent;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $application_id
 * @property ApplicationEvent $event
 * @property string|null $from_stage_id
 * @property string|null $to_stage_id
 * @property string|null $reason_id
 * @property string|null $note
 * @property string|null $actor_id
 * @property Carbon|null $created_at
 */
class ApplicationHistory extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_application_history';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['application_id', 'event', 'from_stage_id', 'to_stage_id', 'reason_id', 'note', 'actor_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event' => ApplicationEvent::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new \LogicException('This ledger is append-only.');
        });
        static::deleting(static function (): never {
            throw new \LogicException('This ledger is append-only.');
        });
    }
}
