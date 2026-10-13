<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The workspace's WhatsApp Business options. One row per workspace; no credential is stored here.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $business_name
 * @property string|null $display_phone
 * @property bool $auto_assign
 * @property bool $auto_create_contacts
 * @property bool $bots_enabled
 * @property bool $feedback_enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Setting extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_settings';

    /** @var list<string> */
    protected $fillable = ['business_name', 'display_phone', 'auto_assign', 'auto_create_contacts', 'bots_enabled', 'feedback_enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auto_assign' => 'boolean',
            'auto_create_contacts' => 'boolean',
            'bots_enabled' => 'boolean',
            'feedback_enabled' => 'boolean',
        ];
    }
}
