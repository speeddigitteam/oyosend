<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Identity\Models\TenantMembership;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An immutable snapshot of an article taken whenever it is saved.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $article_id
 * @property int $version
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property string|null $edited_by
 * @property Carbon $created_at
 * @property-read TenantMembership|null $editor
 */
class KbArticleVersion extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'support_kb_article_versions';

    /** @var list<string> */
    protected $fillable = ['article_id', 'version', 'title', 'summary', 'body', 'edited_by'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Article versions are immutable.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<TenantMembership, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'edited_by');
    }
}
