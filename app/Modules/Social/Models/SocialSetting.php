<?php

declare(strict_types=1);

namespace App\Modules\Social\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property bool $require_approval
 * @property int $account_hourly_limit
 */
class SocialSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'social_settings';

    /** @var list<string> */
    protected $fillable = ['require_approval', 'account_hourly_limit'];

    /** @var array<string, mixed> */
    protected $attributes = ['require_approval' => false, 'account_hourly_limit' => 10];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['require_approval' => 'boolean', 'account_hourly_limit' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Social settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'social.settings.'.$verb;
    }
}
