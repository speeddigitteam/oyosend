<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Workspace settings of the Support app (one row per workspace).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $ticket_prefix
 * @property int $number_padding
 * @property TicketPriority $default_priority
 * @property string|null $default_queue_id
 * @property string|null $default_sla_policy_id
 * @property bool $csat_enabled
 * @property int $csat_expiry_days
 * @property bool $csat_send_email
 * @property int|null $auto_close_days
 */
class Setting extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_settings';

    /** @var list<string> */
    protected $fillable = [
        'ticket_prefix', 'number_padding', 'default_priority', 'default_queue_id', 'default_sla_policy_id',
        'csat_enabled', 'csat_expiry_days', 'csat_send_email', 'auto_close_days',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number_padding' => 'integer',
            'default_priority' => TicketPriority::class,
            'csat_enabled' => 'boolean',
            'csat_expiry_days' => 'integer',
            'csat_send_email' => 'boolean',
            'auto_close_days' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return 'Support settings';
    }
}
