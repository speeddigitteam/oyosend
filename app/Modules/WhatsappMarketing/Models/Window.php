<?php

declare(strict_types=1);

namespace App\Modules\WhatsappMarketing\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\WhatsappMarketing\Enums\WindowOrigin;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A 24-hour conversation window: the unit `whatsapp_marketing.conversations_per_month` counts.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $conversation_id
 * @property WindowOrigin $origin
 * @property CarbonInterface $opened_at
 * @property CarbonInterface $expires_at
 * @property string|null $template_category
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Window extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'whatsapp_windows';

    /** @var list<string> */
    protected $fillable = ['conversation_id', 'origin', 'opened_at', 'expires_at', 'template_category'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'origin' => WindowOrigin::class,
            'opened_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
