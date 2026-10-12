<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per workspace.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $from_name
 * @property string|null $reply_to_email
 * @property bool $quiet_hours_enabled
 * @property string $quiet_hours_start
 * @property string $quiet_hours_end
 * @property string|null $footer_text
 */
class CommunicationSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_settings';

    /** @var list<string> */
    protected $fillable = ['from_name', 'reply_to_email', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end', 'footer_text'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quiet_hours_enabled' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return 'Communication settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'communication.settings.'.$verb;
    }
}
