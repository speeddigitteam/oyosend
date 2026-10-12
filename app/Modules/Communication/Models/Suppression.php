<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Communication\Enums\Channel;
use App\Modules\Communication\Enums\SuppressionReason;
use App\Modules\Communication\Enums\SuppressionScope;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An address the workspace must not message (all, or marketing only).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $contact_id
 * @property string|null $created_by
 * @property Channel $channel
 * @property string $address
 * @property SuppressionScope $scope
 * @property SuppressionReason $reason
 * @property string|null $note
 * @property Carbon $created_at
 */
class Suppression extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_suppressions';

    /** @var list<string> */
    protected $fillable = ['contact_id', 'created_by', 'channel', 'address', 'scope', 'reason', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'scope' => SuppressionScope::class,
            'reason' => SuppressionReason::class,
        ];
    }
}
