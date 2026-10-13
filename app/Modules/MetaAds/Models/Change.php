<?php

declare(strict_types=1);

namespace App\Modules\MetaAds\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\MetaAds\Enums\ChangeAction;
use App\Modules\MetaAds\Enums\ChangeStatus;
use App\Modules\MetaAds\Enums\ObjectLevel;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The receipt of one confirmed change to a Meta object: what it was, what it became, who asked and how
 * Meta answered. Also the idempotency record: one row per confirmation (`request_id`).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $account_id
 * @property string $object_id
 * @property string|null $requested_by
 * @property string $request_id
 * @property ChangeAction $action
 * @property string $field
 * @property ObjectLevel $level
 * @property string $object_name
 * @property string|null $before_value
 * @property string|null $after_value
 * @property string|null $currency
 * @property ChangeStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon $requested_at
 * @property Carbon|null $applied_at
 */
class Change extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'meta_ads_changes';

    /** @var list<string> */
    protected $fillable = [
        'account_id', 'object_id', 'requested_by', 'request_id', 'action', 'field', 'level', 'object_name',
        'before_value', 'after_value', 'currency', 'status', 'error_code', 'error_message', 'requested_at', 'applied_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => ChangeAction::class,
            'level' => ObjectLevel::class,
            'status' => ChangeStatus::class,
            'requested_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }
}
