<?php

declare(strict_types=1);

namespace App\Modules\Social\Comments\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Workspace comment settings: the global automation kill switch and the per-Page hourly cap.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $automation_paused
 * @property string|null $paused_by
 * @property Carbon|null $paused_at
 * @property int $page_hourly_cap
 */
class CommentSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_comment_settings';

    /** @var list<string> */
    protected $fillable = ['automation_paused', 'paused_by', 'paused_at', 'page_hourly_cap'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['automation_paused' => 'boolean', 'paused_at' => 'datetime', 'page_hourly_cap' => 'integer'];
    }
}
