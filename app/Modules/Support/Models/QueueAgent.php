<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $queue_id
 * @property string $agent_id
 */
class QueueAgent extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_queue_agents';

    /** @var list<string> */
    protected $fillable = ['queue_id', 'agent_id'];
}
