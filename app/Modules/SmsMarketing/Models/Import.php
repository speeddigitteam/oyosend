<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The record of one CSV import of group members.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $group_id
 * @property string $file_name
 * @property int $total_rows
 * @property int $added_rows
 * @property int $failed_rows
 * @property list<array{line: int, message: string}>|null $errors
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class Import extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_imports';

    /** @var list<string> */
    protected $fillable = ['group_id', 'file_name', 'total_rows', 'added_rows', 'failed_rows', 'errors', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['errors' => 'array', 'total_rows' => 'integer', 'added_rows' => 'integer', 'failed_rows' => 'integer'];
    }
}
