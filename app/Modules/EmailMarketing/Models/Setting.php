<?php

declare(strict_types=1);

namespace App\Modules\EmailMarketing\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property bool $track_clicks
 * @property int $send_per_minute
 * @property string|null $footer_address
 */
class Setting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'email_marketing_settings';

    /** @var list<string> */
    protected $fillable = ['track_clicks', 'send_per_minute', 'footer_address'];

    /** @var array<string, mixed> */
    protected $attributes = ['track_clicks' => true, 'send_per_minute' => 60];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['track_clicks' => 'boolean', 'send_per_minute' => 'integer'];
    }

    public function auditLabel(): string
    {
        return 'Email marketing settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'email_marketing.settings.'.$verb;
    }
}
