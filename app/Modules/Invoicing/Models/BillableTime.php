<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An approved billable time entry announced by Projects (`projects.time_entry.approved`).
 * The project and time-entry ids are opaque references; the Projects tables are never read.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $invoice_id
 * @property string $project_id
 * @property string $time_entry_id
 * @property string|null $membership_id
 * @property int $minutes
 * @property int $rate_amount
 * @property string $currency
 * @property Carbon|null $invoiced_at
 */
class BillableTime extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_billable_time';

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'project_id', 'time_entry_id', 'membership_id', 'minutes', 'rate_amount', 'currency',
        'invoiced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['minutes' => 'integer', 'rate_amount' => 'integer', 'invoiced_at' => 'datetime'];
    }
}
