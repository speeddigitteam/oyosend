<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property bool $management_enabled
 * @property int $lookback_days
 */
class MetaAdsSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'meta_ads_settings';

    /** @var list<string> */
    protected $fillable = ['management_enabled', 'lookback_days'];

    /** @var array<string, mixed> */
    protected $attributes = ['management_enabled' => false, 'lookback_days' => 30];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['management_enabled' => 'boolean', 'lookback_days' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Meta Ads settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'meta_ads.settings.'.$verb;
    }
}
