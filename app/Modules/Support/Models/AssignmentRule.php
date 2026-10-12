<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\TicketChannel;
use App\Modules\Support\Enums\TicketPriority;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the assignment rule table: all non-null `match_*` columns must match, the first rule by
 * position wins. No expressions and no code: only these columns.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $position
 * @property bool $is_active
 * @property TicketChannel|null $match_channel
 * @property TicketPriority|null $match_priority
 * @property string|null $match_category_id
 * @property string|null $match_company_id
 * @property string|null $match_keyword
 * @property string $queue_id
 * @property string|null $assign_agent_id
 * @property TicketPriority|null $set_priority
 */
class AssignmentRule extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_assignment_rules';

    /** @var list<string> */
    protected $fillable = [
        'name', 'position', 'is_active', 'match_channel', 'match_priority', 'match_category_id', 'match_company_id',
        'match_keyword', 'queue_id', 'assign_agent_id', 'set_priority',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'match_channel' => TicketChannel::class,
            'match_priority' => TicketPriority::class,
            'set_priority' => TicketPriority::class,
        ];
    }
}
