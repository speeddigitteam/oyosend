<?php

declare(strict_types=1);

namespace App\Modules\Communication\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $membership_id
 * @property string $channel
 * @property string $category
 * @property bool $enabled
 */
class NotificationPreference extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'communication_notification_preferences';

    /** @var list<string> */
    protected $fillable = ['membership_id', 'channel', 'category', 'enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
