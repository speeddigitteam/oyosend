<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One bulk-scheduling CSV import. The file itself is not kept: only its hash (so the same
 * file is not imported twice) and the per-row result.
 *
 * @property string $id
 * @property Carbon|null $created_at
 * @property string $tenant_id
 * @property string $file_name
 * @property string $file_sha256
 * @property string $mode
 * @property int $total_rows
 * @property int $created_rows
 * @property int $failed_rows
 * @property list<array{line: int, message: string}>|null $errors
 * @property string|null $created_by
 */
class Import extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_imports';

    /** @var list<string> */
    protected $fillable = ['file_name', 'file_sha256', 'mode', 'total_rows', 'created_rows', 'failed_rows', 'errors', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['errors' => 'array', 'total_rows' => 'integer', 'created_rows' => 'integer', 'failed_rows' => 'integer'];
    }
}
