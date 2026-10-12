<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A business-hours calendar: weekly working windows in a timezone plus holidays.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $timezone
 * @property array<string, list<array{0: string, 1: string}>> $hours
 * @property list<string>|null $holidays
 * @property bool $is_active
 */
class Calendar extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_calendars';

    /** @var list<string> */
    protected $fillable = ['name', 'timezone', 'hours', 'holidays', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['hours' => 'array', 'holidays' => 'array', 'is_active' => 'boolean'];
    }
}
