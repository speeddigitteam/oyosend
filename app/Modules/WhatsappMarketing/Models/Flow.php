<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\FlowMatch;
use App\Modules\WhatsappMarketing\Enums\FlowTrigger;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A declarative chatbot flow: keywords or a welcome trigger, then a closed list of steps. No code and no expressions are ever stored or run.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property FlowTrigger $trigger
 * @property FlowMatch|null $match_type
 * @property list<string>|null $keywords
 * @property list<array<string, mixed>> $actions
 * @property int $priority
 * @property bool $is_active
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Flow extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_flows';

    /** @var list<string> */
    protected $fillable = ['name', 'trigger', 'match_type', 'keywords', 'actions', 'priority', 'is_active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'trigger' => FlowTrigger::class,
            'match_type' => FlowMatch::class,
            'keywords' => 'array',
            'actions' => 'array',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
