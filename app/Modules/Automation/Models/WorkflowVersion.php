<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Automation\Enums\VersionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One version of a workflow graph. A draft is editable; a published version is an immutable snapshot
 * (the graph and its hash can never change) and only published versions start live runs.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $workflow_id
 * @property int $number
 * @property VersionStatus $status
 * @property int $schema_version
 * @property array<string, mixed> $graph
 * @property string|null $graph_hash
 * @property string|null $trigger_key
 * @property array<string, mixed>|null $trigger_config
 * @property string|null $note
 * @property string|null $created_by_membership_id
 * @property string|null $published_by_membership_id
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkflowVersion extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_workflow_versions';

    /** @var list<string> */
    protected $fillable = [
        'workflow_id', 'number', 'status', 'schema_version', 'graph', 'graph_hash', 'trigger_key', 'trigger_config',
        'note', 'created_by_membership_id', 'published_by_membership_id', 'published_at', 'archived_at',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => VersionStatus::class,
            'graph' => 'array',
            'trigger_config' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A published snapshot is immutable: the only change it may undergo is being archived.
        static::updating(function (self $version): void {
            if ($version->getRawOriginal('status') === VersionStatus::Draft->value) {
                return;
            }

            $illegal = array_diff(array_keys($version->getDirty()), ['status', 'archived_at', 'updated_at']);

            if ($illegal !== []) {
                throw new LogicException('A published workflow version is immutable.');
            }

            if ($version->status === VersionStatus::Draft) {
                throw new LogicException('A published workflow version cannot become a draft again.');
            }
        });

        static::deleting(function (self $version): void {
            if ($version->getRawOriginal('status') !== VersionStatus::Draft->value) {
                throw new LogicException('A published workflow version cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class, 'workflow_id');
    }
}
