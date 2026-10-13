<?php

declare(strict_types=1);

namespace App\Modules\Social\LeadAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One step of a lead's processing: received, fetched, captured, duplicate, failed, waiting, retry, skipped.
 * Messages are ours (never the platform's raw text, never answers).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $lead_id
 * @property string $step
 * @property string|null $code
 * @property string|null $message
 * @property Carbon $created_at
 */
class LeadAdLog extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'social_lead_ad_logs';

    /** @var list<string> */
    protected $fillable = ['lead_id', 'step', 'code', 'message', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
