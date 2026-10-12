<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ticket category, optionally routing to a default queue.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $queue_id
 * @property string $name
 * @property bool $is_active
 */
class Category extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_categories';

    /** @var list<string> */
    protected $fillable = ['queue_id', 'name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
