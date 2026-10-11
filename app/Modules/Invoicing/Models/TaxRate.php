<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $rate_bps
 * @property bool $is_default
 * @property bool $is_active
 */
class TaxRate extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_tax_rates';

    /** @var list<string> */
    protected $fillable = ['name', 'rate_bps', 'is_default', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rate_bps' => 'integer', 'is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'invoicing.tax_rate.'.$verb;
    }
}
