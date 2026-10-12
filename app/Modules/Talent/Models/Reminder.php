<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $kind
 * @property string $subject_id
 * @property string $reminder_key
 * @property Carbon|null $due_on
 * @property Carbon|null $created_at
 */
class Reminder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_reminders';

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['kind', 'subject_id', 'reminder_key', 'due_on'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
