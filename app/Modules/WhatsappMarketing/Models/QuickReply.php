<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A saved reply an agent inserts into the composer. Only the allow-listed `{{placeholders}}` are substituted.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $shortcut
 * @property string $title
 * @property string $body
 * @property bool $is_active
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class QuickReply extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_quick_replies';

    /** @var list<string> */
    protected $fillable = ['shortcut', 'title', 'body', 'is_active', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
