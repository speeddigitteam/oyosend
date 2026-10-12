<?php

declare(strict_types=1);

namespace App\Modules\SmsMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace SMS Marketing settings (one row per workspace, created on first use).
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $daily_cap
 * @property int $throttle_per_minute
 * @property string|null $opt_out_wording
 * @property int|null $segment_cost_amount
 * @property string|null $currency
 * @property string|null $default_sender_id
 */
class Setting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'sms_marketing_settings';

    /** @var list<string> */
    protected $fillable = ['daily_cap', 'throttle_per_minute', 'opt_out_wording', 'segment_cost_amount', 'currency', 'default_sender_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['daily_cap' => 'integer', 'throttle_per_minute' => 'integer', 'segment_cost_amount' => 'integer'];
    }

    /** @return BelongsTo<SenderId, $this> */
    public function defaultSender(): BelongsTo
    {
        return $this->belongsTo(SenderId::class, 'default_sender_id');
    }

    public function auditLabel(): string
    {
        return 'SMS Marketing settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'sms_marketing.settings.'.$verb;
    }
}
