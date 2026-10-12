<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property int $default_probation_days
 * @property int $probation_reminder_days
 * @property int $certification_reminder_days
 * @property int $candidate_retention_months
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TalentSetting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_settings';

    /** @var list<string> */
    protected $fillable = ['default_probation_days', 'probation_reminder_days', 'certification_reminder_days', 'candidate_retention_months'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_probation_days' => 'integer',
            'probation_reminder_days' => 'integer',
            'certification_reminder_days' => 'integer',
            'candidate_retention_months' => 'integer',
        ];
    }

    public function auditLabel(): string
    {
        return (string) ('Talent settings');
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.settings.'.$verb;
    }
}
