<?php

declare(strict_types=1);

namespace App\Core\Audit\Models;

use App\Core\Tenancy\Concerns\ScopedToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the audit trail. Append-only: entries are never changed or
 * deleted by application code (retention is a scheduled, platform-level job).
 *
 * Tenant entries are tenant-scoped on read. Platform entries have no
 * `tenant_id` and are only reachable through an explicit cross-tenant query.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string $event
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $properties
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use HasUlids;
    use ScopedToTenant;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'actor_type',
        'actor_id',
        'actor_label',
        'event',
        'subject_type',
        'subject_id',
        'subject_label',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new LogicException('Audit log entries are immutable.'));
        static::deleting(static fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }
}
