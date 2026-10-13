<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Whether a workspace turns inbound WhatsApp conversations into CRM leads, and the keywords that mark a message
 * a lead. One row per workspace, created on first use. Off by default: nothing reaches the CRM until an owner
 * switches it on. No customer content and no credential are stored here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $enabled
 * @property list<string>|null $keywords
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class LeadCaptureSetting extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_lead_capture_settings';

    /** @var list<string> */
    protected $fillable = ['enabled', 'keywords'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'keywords' => 'array',
        ];
    }
}
