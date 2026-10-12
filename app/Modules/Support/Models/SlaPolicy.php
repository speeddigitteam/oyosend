<?php

declare(strict_types=1);

namespace App\Modules\Support\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use App\Modules\Support\Models\Concerns\AuditsConfig;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Response and resolution targets per priority, in integer minutes of the policy's calendar.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $calendar_id
 * @property string $name
 * @property array<string, array<string, int>> $targets
 * @property bool $pause_on_waiting
 * @property bool $is_active
 * @property-read Calendar|null $calendar
 */
class SlaPolicy extends Model
{
    use AuditsConfig;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'support_sla_policies';

    /** @var list<string> */
    protected $fillable = ['calendar_id', 'name', 'targets', 'pause_on_waiting', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['targets' => 'array', 'pause_on_waiting' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Calendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(Calendar::class);
    }

    /** @return array{first_response: int, resolution: int}|null */
    public function targetFor(string $priority): ?array
    {
        $target = $this->targets[$priority] ?? null;

        return is_array($target) ? [
            'first_response' => (int) ($target['first_response'] ?? 0),
            'resolution' => (int) ($target['resolution'] ?? 0),
        ] : null;
    }
}
