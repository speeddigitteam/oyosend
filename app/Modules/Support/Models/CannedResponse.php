<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A reusable reply. The body may contain only the allow-listed `{{placeholders}}` of `PlaceholderRenderer`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $body
 * @property bool $is_active
 * @property string|null $created_by
 * @property Carbon|null $updated_at
 */
class CannedResponse extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_canned_responses';

    /** @var list<string> */
    protected $fillable = ['name', 'body', 'is_active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
