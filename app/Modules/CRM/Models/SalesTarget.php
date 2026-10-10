<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Core\Access\Concerns\HasRecordOwner;
use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $currency
 * @property int $target_amount
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
class SalesTarget extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasRecordOwner;
    use HasUlids;

    protected $table = 'crm_sales_targets';

    /** @var list<string> */
    protected $fillable = ['name', 'currency', 'target_amount', 'period_start', 'period_end'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'target_amount' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'crm.sales_target.'.$verb;
    }
}
