<?php

declare(strict_types=1);

namespace App\Modules\Ai\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property bool $enabled
 * @property bool $chat_enabled
 * @property bool $drafting_enabled
 * @property bool $automation_enabled
 * @property list<string> $data_scopes
 * @property int $retention_days
 * @property bool $redact_pii
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Setting extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'ai_settings';

    /** @var list<string> */
    protected $fillable = ['enabled', 'chat_enabled', 'drafting_enabled', 'automation_enabled', 'data_scopes', 'retention_days', 'redact_pii'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'chat_enabled' => 'boolean',
            'drafting_enabled' => 'boolean',
            'automation_enabled' => 'boolean',
            'data_scopes' => 'array',
            'retention_days' => 'integer',
            'redact_pii' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return 'AI settings';
    }

    public function auditEventName(string $verb): string
    {
        return 'ai.settings.'.$verb;
    }
}
