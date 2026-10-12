<?php

declare(strict_types=1);

namespace App\Modules\Automation\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An outbound destination a webhook node may call. A workflow can only name an endpoint that someone
 * holding `automation.webhooks.manage` registered (authorized); it never carries a raw URL.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $url
 * @property string|null $secret
 * @property bool $is_active
 * @property Carbon|null $last_called_at
 * @property int|null $last_status_code
 * @property string|null $created_by_membership_id
 */
class WebhookEndpoint extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'automation_webhook_endpoints';

    /** @var list<string> */
    protected $fillable = ['name', 'url', 'secret', 'is_active', 'last_called_at', 'last_status_code', 'created_by_membership_id'];

    /** @var list<string> */
    protected $hidden = ['secret'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'is_active' => 'boolean',
            'last_called_at' => 'datetime',
        ];
    }
}
