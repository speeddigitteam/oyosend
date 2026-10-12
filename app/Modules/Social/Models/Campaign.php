<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Social\Enums\CampaignStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property CampaignStatus $status
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
class Campaign extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_campaigns';

    /** @var list<string> */
    protected $fillable = ['name', 'description', 'status', 'starts_on', 'ends_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => CampaignStatus::class, 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function auditEventName(string $verb): string
    {
        return 'social.campaign.'.$verb;
    }
}
