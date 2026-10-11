<?php

declare(strict_types=1);

namespace App\Modules\Invoicing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string $reminder_key
 * @property string $sent_to
 * @property Carbon $sent_on
 */
class ReminderLog extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'invoicing_reminder_logs';

    /** @var list<string> */
    protected $fillable = ['invoice_id', 'reminder_key', 'sent_to', 'sent_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sent_on' => 'date'];
    }
}
