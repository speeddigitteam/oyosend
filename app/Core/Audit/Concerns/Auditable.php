<?php

declare(strict_types=1);

namespace App\Core\Audit\Concerns;

use App\Core\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records a model's creation, changes and deletion in the audit trail.
 *
 * Hidden attributes and timestamps are left out; override
 * {@see auditExcluded()} for anything else that should not be recorded.
 * Use {@see AuditLogger::record()} directly for business-level events
 * ("member invited") that are not a plain attribute change.
 *
 * @phpstan-require-extends Model
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(function (self $model): void {
            app(AuditLogger::class)->record(
                $model->auditEventName('created'),
                $model,
                ['attributes' => $model->auditableAttributes($model->getAttributes())],
            );
        });

        // `updated` fires before the originals are synced, so getRawOriginal()
        // still holds the values from before this save.
        static::updated(function (self $model): void {
            $changes = $model->auditableAttributes($model->getChanges());

            if ($changes === []) {
                return;
            }

            app(AuditLogger::class)->record($model->auditEventName('updated'), $model, [
                'old' => array_intersect_key($model->auditableAttributes($model->getRawOriginal()), $changes),
                'attributes' => $changes,
            ]);
        });

        static::deleted(function (self $model): void {
            app(AuditLogger::class)->record($model->auditEventName('deleted'), $model);
        });
    }

    public function auditEventName(string $verb): string
    {
        return Str::snake(class_basename($this)).'.'.$verb;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditableAttributes(array $attributes): array
    {
        $excluded = [
            ...$this->getHidden(),
            ...$this->auditExcluded(),
            $this->getKeyName(),
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        return array_diff_key($attributes, array_flip($excluded));
    }

    /**
     * Extra attributes to keep out of the trail (noisy or sensitive ones).
     *
     * @return list<string>
     */
    protected function auditExcluded(): array
    {
        return [];
    }
}
