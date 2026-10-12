<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $action
 * @property string $idempotency_key
 * @property array<string, mixed> $output
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AutomationActionReceipt extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_automation_action_receipts';

    /** @var list<string> */
    protected $fillable = ['action', 'idempotency_key', 'output'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'output' => 'array',
        ];
    }
}
