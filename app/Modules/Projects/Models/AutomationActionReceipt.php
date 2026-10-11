<?php

declare(strict_types=1);

namespace App\Modules\Projects\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $action
 * @property string $idempotency_key
 * @property array<string, mixed> $output
 */
class AutomationActionReceipt extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'project_automation_action_receipts';

    protected $fillable = ['action', 'idempotency_key', 'output'];

    protected function casts(): array
    {
        return ['output' => 'array'];
    }
}
