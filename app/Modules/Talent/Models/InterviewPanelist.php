<?php

declare(strict_types=1);

namespace App\Modules\Talent\Models;

use App\Core\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $interview_id
 * @property string $employee_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InterviewPanelist extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'talent_interview_panel';

    /** @var list<string> */
    protected $fillable = ['interview_id', 'employee_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
        ];
    }

    /** @return BelongsTo<Interview, $this> */
    public function interview(): BelongsTo
    {
        return $this->belongsTo(Interview::class, 'interview_id');
    }
}
