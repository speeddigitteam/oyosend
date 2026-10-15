<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Enums\VisitorDetails;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Website chat widget settings of a workspace (one row per workspace). Without a row, or while `enabled` is off, the
 * widget, its frame and its API answer 404.
 *
 * @property string $id
 * @property string $tenant_id
 * @property bool $enabled
 * @property list<string>|null $allowed_origins
 * @property string|null $widget_title
 * @property string|null $greeting
 * @property VisitorDetails $visitor_details
 * @property string|null $offline_note
 */
class ChatSetting extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_chat_settings';

    /** @var list<string> */
    protected $fillable = ['enabled', 'allowed_origins', 'widget_title', 'greeting', 'visitor_details', 'offline_note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'allowed_origins' => 'array',
            'visitor_details' => VisitorDetails::class,
        ];
    }

    /**
     * The canonical origins the widget may be embedded on.
     *
     * @return list<string>
     */
    public function origins(): array
    {
        return array_values(array_filter($this->allowed_origins ?? [], 'is_string'));
    }

    public function auditLabel(): string
    {
        return 'Website chat settings';
    }
}
