<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property int $period
 * @property int $last_number
 */
class NumberSequence extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_number_sequences';

    /** @var list<string> */
    protected $fillable = ['kind', 'period', 'last_number'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period' => 'integer', 'last_number' => 'integer'];
    }
}
