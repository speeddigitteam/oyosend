<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Audit\Concerns\Auditable;
use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $title
 * @property string|null $code
 * @property string|null $provider
 * @property string|null $description
 * @property int|null $duration_minutes
 * @property int|null $validity_months
 * @property bool $is_mandatory
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Course extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_courses';

    /** @var list<string> */
    protected $fillable = ['title', 'code', 'provider', 'description', 'duration_minutes', 'validity_months', 'is_mandatory', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'validity_months' => 'integer',
            'is_mandatory' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<TrainingSession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class, 'course_id')->orderBy('starts_at');
    }

    public function auditLabel(): string
    {
        return (string) ($this->title);
    }

    public function auditEventName(string $verb): string
    {
        return 'talent.course.'.$verb;
    }
}
